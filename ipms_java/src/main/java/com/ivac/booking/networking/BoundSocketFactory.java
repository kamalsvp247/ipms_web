package com.ivac.booking.networking;

import javax.net.SocketFactory;
import java.io.IOException;
import java.net.InetAddress;
import java.net.InetSocketAddress;
import java.net.Socket;

/**
 * SocketFactory that binds every socket it creates to a fixed local source address before
 * connecting. OkHttp calls the no-arg createSocket() and connects the returned socket itself,
 * so binding an unconnected socket here pins the egress source IP for that connection.
 *
 * Used to route an account's direct IVAC traffic out of a specific globally-routable IPv6
 * address handed out by Ipv6SourcePool.
 */
public final class BoundSocketFactory extends SocketFactory {

    private final InetAddress localAddress;

    public BoundSocketFactory(InetAddress localAddress) {
        this.localAddress = localAddress;
    }

    private Socket newBoundSocket() throws IOException {
        Socket socket = new Socket();
        socket.bind(new InetSocketAddress(localAddress, 0));

        return socket;
    }

    @Override
    public Socket createSocket() throws IOException {
        return newBoundSocket();
    }

    @Override
    public Socket createSocket(String host, int port) throws IOException {
        Socket socket = newBoundSocket();
        socket.connect(new InetSocketAddress(host, port));

        return socket;
    }

    @Override
    public Socket createSocket(String host, int port, InetAddress localHost, int localPort) throws IOException {
        Socket socket = newBoundSocket();
        socket.connect(new InetSocketAddress(host, port));

        return socket;
    }

    @Override
    public Socket createSocket(InetAddress host, int port) throws IOException {
        Socket socket = newBoundSocket();
        socket.connect(new InetSocketAddress(host, port));

        return socket;
    }

    @Override
    public Socket createSocket(InetAddress address, int port, InetAddress localAddress, int localPort) throws IOException {
        Socket socket = newBoundSocket();
        socket.connect(new InetSocketAddress(address, port));

        return socket;
    }

    public InetAddress getLocalAddress() {
        return localAddress;
    }
}
