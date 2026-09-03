<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Run via Maven exec (main class) instead of JAR
    |--------------------------------------------------------------------------
    |
    | When true, the bot is started with `mvn exec:java -Dexec.mainClass=...`
    | from the bot CWD (ipms_java). No JAR build required. When false, the bot
    | is started with `java -jar <jar_path>` and jar_path must exist.
    |
    */
    'use_maven_exec' => env('BOT_USE_MAVEN_EXEC', true),

    /*
    |--------------------------------------------------------------------------
    | Main class (for Maven exec)
    |--------------------------------------------------------------------------
    */
    'main_class' => env('BOT_MAIN_CLASS', 'com.ivac.booking.App'),

    /*
    |--------------------------------------------------------------------------
    | Bot JAR Path (used only when use_maven_exec is false)
    |--------------------------------------------------------------------------
    |
    | Absolute path to the ivac-booking fat JAR. When using Maven locally this
    | is typically ipms_java/target/ivac-booking.jar relative to the project.
    |
    */
    'jar_path' => env('BOT_JAR_PATH', base_path('ipms_java/target/ivac-booking.jar')),

    /*
    |--------------------------------------------------------------------------
    | Bot Working Directory
    |--------------------------------------------------------------------------
    |
    | The working directory used when spawning the Java process.
    |
    */
    'cwd' => env('BOT_CWD', base_path('ipms_java')),

    /*
    |--------------------------------------------------------------------------
    | PID File
    |--------------------------------------------------------------------------
    |
    | File where the running bot's PID is stored so we can check status, stop,
    | or kill it later.
    |
    */
    'pid_file' => env('BOT_PID_FILE', storage_path('app/bot.pid')),

    /*
    |--------------------------------------------------------------------------
    | Bot Meta File (PID + started_at for uptime)
    |--------------------------------------------------------------------------
    */
    'meta_file' => env('BOT_META_FILE', storage_path('app/bot_meta.json')),

    /*
    |--------------------------------------------------------------------------
    | Bot Log File
    |--------------------------------------------------------------------------
    |
    | Stdout/stderr of the bot process is redirected here so we can read
    | recent output from the UI.
    |
    */
    'log_file' => env('BOT_LOG_FILE', storage_path('logs/bot.log')),

    /*
    |--------------------------------------------------------------------------
    | Process Match Pattern
    |--------------------------------------------------------------------------
    |
    | Pattern used with pgrep/pkill to find all running bot JVM processes.
    | Used by "Kill All" to force-stop every matching process.
    |
    */
    'process_pattern' => env('BOT_PROCESS_PATTERN', 'com.ivac.booking.App'),

    /*
    |--------------------------------------------------------------------------
    | Captcha JAR Path
    |--------------------------------------------------------------------------
    |
    | Absolute path to the captcha-solver JAR. This process is separate from
    | the booking bot and is managed by CaptchaControlController.
    |
    */
    'captcha_jar_path' => env('CAPTCHA_JAR_PATH', base_path('ipms_java/target/ivac-booking.jar')),
];
