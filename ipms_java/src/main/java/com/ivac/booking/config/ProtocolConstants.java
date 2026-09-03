package com.ivac.booking.config;

import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.Objects;
import java.util.Set;
import java.util.TreeSet;

/**
 * The bundle-derived IVAC request constants: the endpoint paths, the two rotating x-sec headers, and
 * the reserve/payment deployment IDs. IVAC rotates all of these on redeploy.
 *
 * These are held apart from the rest of AppConfig because they are the only config values that can
 * be swapped under a running race. Two properties make that safe:
 * - They are stateless. Every call site reads them while building a request; nothing is derived from
 *   them at cycle start, so a swap takes effect on the next request and needs no coordination.
 * - They are published as one immutable snapshot, so a reader can never see a new reserve ID paired
 *   with an old reserve path template.
 *
 * Topology config - accounts, window times, tick schedules - is deliberately NOT part of this record.
 * Worker threads and tick schedules are built from those values at cycle start, so changing them in
 * flight would desynchronise the race; they still require a restart.
 *
 * The swap matters because the portal cannot learn the new values before the window opens: IVAC
 * serves a time-gated notice page that hides the JS bundle until roughly a minute or two after the
 * nominal window start, so the only usable extraction happens while the race is already live.
 */
public record ProtocolConstants(
        Map<String, String> endpoints,
        String reserveSlotId,
        String paymentConfigId,
        String reserveRequestMeta
) {

    public ProtocolConstants {
        endpoints = copyUsable(endpoints);
    }

    /**
     * Merges fresh constants over these, per key, keeping the current value wherever the fresh set
     * omits a key or carries a blank one. A partial or empty payload therefore can never downgrade a
     * good value back to the compiled-in default - the same last-known-good rule the portal applies
     * when it syncs the bundle.
     */
    public ProtocolConstants mergedWith(ProtocolConstants fresh) {
        if (fresh == null) {
            return this;
        }

        Map<String, String> merged = new LinkedHashMap<>(this.endpoints);
        merged.putAll(fresh.endpoints);

        return new ProtocolConstants(
                merged,
                firstUsable(fresh.reserveSlotId, this.reserveSlotId),
                firstUsable(fresh.paymentConfigId, this.paymentConfigId),
                firstUsable(fresh.reserveRequestMeta, this.reserveRequestMeta)
        );
    }

    /**
     * A human-readable summary of what changed between these constants and a newer set, for the
     * swap log line. Returns an empty string when nothing changed.
     */
    public String describeChanges(ProtocolConstants updated) {
        if (updated == null) {
            return "";
        }

        StringBuilder changes = new StringBuilder();

        for (String key : new TreeSet<>(union(this.endpoints.keySet(), updated.endpoints.keySet()))) {
            append(changes, key, this.endpoints.get(key), updated.endpoints.get(key));
        }

        append(changes, "reserveSlotId", this.reserveSlotId, updated.reserveSlotId);
        append(changes, "paymentConfigId", this.paymentConfigId, updated.paymentConfigId);
        append(changes, "reserveRequestMeta", this.reserveRequestMeta, updated.reserveRequestMeta);

        return changes.toString();
    }

    private static void append(StringBuilder target, String key, String before, String after) {
        if (Objects.equals(before, after)) {
            return;
        }

        if (!target.isEmpty()) {
            target.append(", ");
        }

        target.append(key).append(": ").append(before).append(" -> ").append(after);
    }

    private static TreeSet<String> union(Set<String> left, Set<String> right) {
        TreeSet<String> all = new TreeSet<>(left);
        all.addAll(right);

        return all;
    }

    /**
     * Defensive copy that drops null and blank entries, so the map published in a snapshot is both
     * immutable and free of values that would defeat the per-key fallback in AppConfig. Gson hands
     * back a mutable LinkedTreeMap, so copying is what actually makes the snapshot immutable.
     */
    private static Map<String, String> copyUsable(Map<String, String> source) {
        if (source == null || source.isEmpty()) {
            return Map.of();
        }

        Map<String, String> copy = new LinkedHashMap<>();

        for (Map.Entry<String, String> entry : source.entrySet()) {
            if (entry.getKey() != null && entry.getValue() != null && !entry.getValue().isBlank()) {
                copy.put(entry.getKey(), entry.getValue());
            }
        }

        return Collections.unmodifiableMap(copy);
    }

    private static String firstUsable(String preferred, String fallback) {
        return preferred != null && !preferred.isBlank() ? preferred : fallback;
    }
}
