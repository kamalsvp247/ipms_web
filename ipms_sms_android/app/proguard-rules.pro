# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
# Duronto SMS Forwarder — R8 / ProGuard keep rules
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

# Keep line numbers for readable crash reports; hide original source file name.
-keepattributes SourceFile,LineNumberTable
-renamesourcefileattribute SourceFile

# Annotations & generic signatures are required by Retrofit/Gson reflection.
-keepattributes Signature,RuntimeVisibleAnnotations,AnnotationDefault,*Annotation*,EnclosingMethod,InnerClasses

# ── Data models (serialized by Gson via Retrofit) ──
# Field names must survive obfuscation so JSON keys keep matching.
-keep class site.mashmininet.smsforwarder.data.model.** { *; }

# ── Retrofit ──
-keepattributes Exceptions
-keep,allowobfuscation,allowshrinking interface retrofit2.Call
-keep,allowobfuscation,allowshrinking class retrofit2.Response
-keep,allowobfuscation,allowshrinking class kotlin.coroutines.Continuation
# Keep the service interface methods (annotations drive the proxy).
-keep,allowobfuscation interface site.mashmininet.smsforwarder.data.api.** { *; }
-if interface * { @retrofit2.http.* <methods>; }
-keep,allowobfuscation interface <1>

# ── Gson ──
-keep class com.google.gson.reflect.TypeToken { *; }
-keep class * extends com.google.gson.reflect.TypeToken
-keep public class * implements java.lang.reflect.Type
# Anonymous TypeToken subclasses used in SmsRepository (Map / List parsing).
-keepclassmembers,allowobfuscation class * {
    @com.google.gson.annotations.SerializedName <fields>;
}

# ── OkHttp / Okio (known safe suppressions) ──
-dontwarn okhttp3.internal.platform.**
-dontwarn org.conscrypt.**
-dontwarn org.bouncycastle.**
-dontwarn org.openjsse.**

# ── Kotlin coroutines ──
-dontwarn kotlinx.coroutines.**
-keepclassmembers class kotlinx.coroutines.** { volatile <fields>; }

# ── WorkManager workers (instantiated reflectively by the default factory) ──
-keep class * extends androidx.work.ListenableWorker { public <init>(...); }

# ── App entry points referenced from the manifest ──
-keep class site.mashmininet.smsforwarder.SmsForwarderApp { *; }
-keep class site.mashmininet.smsforwarder.service.** { *; }
-keep class site.mashmininet.smsforwarder.receiver.** { *; }
-keep class site.mashmininet.smsforwarder.admin.** { *; }

# Enum valueOf is used to deserialize ForwardStatus from disk.
-keepclassmembers enum * {
    public static **[] values();
    public static ** valueOf(java.lang.String);
}
