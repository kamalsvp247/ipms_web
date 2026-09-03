package com.ivac.booking;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.io.IOException;

public class App {

    private static final Logger log = LoggerFactory.getLogger(App.class);

    static void main(String[] args) {
        try {
            AppStartup.run(args);
        } catch (IOException e) {
            log.error("Startup failed: {}", e.getMessage());
        }
    }
}
