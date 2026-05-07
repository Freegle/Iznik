# Controller worker — serves the rspamd web UI on port 11334.
# Password is required because Traefik proxies the UI from a non-secure_ip origin.
# Generate a real hash for production: `rspamadm pw --encrypt`
password = "q1";
