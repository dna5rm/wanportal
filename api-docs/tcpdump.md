# Testing DSCP/TOS

A monitor's DSCP class is intended to mark its probe packets. The shipped
netping agent maps the DSCP name to a TOS byte for Net::Ping, but
Net::Ping does not reliably mark packets: monitors that request anything
other than Best Effort probe without dependable marking and log a warning
that points to `socket-agent.pl`. To verify marking behavior in practice,
run `socket-agent.pl` from a repository checkout; it implements DSCP/TOS
marking with raw sockets.

To read the marking from captured traffic, capture the probe packets sent
toward a target and examine the tos field in tcpdump's verbose output
(EF, for example, appears as `tos 0xb8`):

```sh
tcpdump -i any -v -nn 'host <target-address> and (icmp or tcp)'
```

Portal HTTP traffic arrives on Apache port 80 inside the container and is
published as port 3385 on the host:

```sh
tcpdump -i any -v -nn 'dst port 3385'
```