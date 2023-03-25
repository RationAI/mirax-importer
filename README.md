# Mirax Importer

This set of simple scripts serves as MIRAX importing interface
for our browser. It uploads MIRAX to server with optional MD5
checksum, creates a TIFF pyramid for optimized viewing, 
records file into the database and prepares the structure
for browser viewing. It also enables NN analysis execution
and active status monitoring.

Requirements: a HTTP server with PHP, ``xo_db`` database API,
optional connection to (non-open source) ``histopipe`` analysis
pipeline. For label extraction, python with openslide is required.
Full integration is defined in our docker compose system.

The use as of now requires extreme configurations on PHP:
high upload size (at least 2GB per file) and infinite
execution time (for analysis). It is advised to
use some auth for access.
