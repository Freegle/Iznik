# Milter worker — receives connections from Postfix on port 11332.
# rspamd scores each message and returns a milter response.
bind_socket = "rspamd:11332";
