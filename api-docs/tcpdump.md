# Testing DSCP/TOS

A monitor's DSCP class is meant to mark its probe packets. The shipped
netping agent maps the DSCP name to a TOS byte for Net::Ping, but
Net::Ping does not reliably mark packets: monitors that request
anything other than Best Effort probe without dependable marking and
log a warning pointing at `socket-agent.pl`. To test markings for real,
run `socket-agent.pl` from a checkout — it implements DSCP/TOS with
raw sockets.

To read the marking off the wire, capture probe packets toward a
target and check the tos field in the verbose output (EF, for example,
shows up as tos 0xb8):

    tcpdump -i any -v -nn 'host <target-address> and (icmp or tcp)'

Portal HTTP traffic arrives on Apache port 80 inside the container,
published as port 3385 on the host:

    tcpdump -i any -v -nn 'dst port 3385'