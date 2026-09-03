package com.ivac.booking.model.response;

import com.google.gson.annotations.SerializedName;

public class ReserveSlotResponse {
    @SerializedName("reservationId")
    private String reservationId;

    @SerializedName("status")
    private String status;

    @SerializedName("message")
    private String message;

    @SerializedName("appointmentDate")
    private String appointmentDate;

    @SerializedName("reserveTtlSeconds")
    private int reserveTtlSeconds;

    public String getReservationId() {
        return reservationId;
    }

    public String getStatus() {
        return status;
    }

    public String getMessage() {
        return message;
    }

    public String getAppointmentDate() {
        return appointmentDate;
    }

    public int getReserveTtlSeconds() {
        return reserveTtlSeconds;
    }
}
