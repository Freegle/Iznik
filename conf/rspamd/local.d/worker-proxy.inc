# Milter worker: receives connections from Postfix on port 11332.
# rspamd scores each message and returns a milter response.
#
# Bind all interfaces ("*"), NOT the container hostname. bind_socket is the
# LISTEN address; binding "rspamd:11332" made rspamd DNS-resolve its own name at
# config-parse time, and when that resolution was not ready the worker died with
# "cannot parse bind line: rspamd:11332" (Temporary failure in name resolution),
# crash-looping the container and blocking everything that depends on it. Postfix
# still reaches it via "inet:rspamd:11332" (conf/postfix/main.cf) because "*"
# listens on the container IP that name resolves to. "*:11332" is also the rspamd
# proxy-worker milter default.
bind_socket = "*:11332";
