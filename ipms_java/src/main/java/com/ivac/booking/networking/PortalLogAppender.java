package com.ivac.booking.networking;

import ch.qos.logback.classic.spi.ILoggingEvent;
import ch.qos.logback.core.AppenderBase;

/**
 * Logback appender that forwards SLF4J log entries to PortalLogShipper
 * so they appear in the portal slot log viewer. Silent no-op until
 * PortalLogShipper.init() is called (i.e. local mode logs are not shipped).
 */
public class PortalLogAppender extends AppenderBase<ILoggingEvent> {

    @Override
    protected void append(ILoggingEvent event) {
        String label = event.getLevel().toString();
        String message = event.getFormattedMessage();
        PortalLogShipper.enqueueConsole(null, label, message);
    }
}
