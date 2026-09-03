package com.ivac.booking.service.slot;

import com.ivac.booking.worker.RaceContext;

public interface SlotReservationService {
    ShotOutcome fireOneShot(RaceContext context, String captchaValue, String label);
}
