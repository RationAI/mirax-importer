#!/bin/bash
mkdir -p /var/www/html/importer && git clone --single-branch --branch kubernetes https://github.com/RationAI/mirax-importer /var/www/html/importer
mkdir -p /var/www/html/xo_db && git clone https://github.com/RationAI/xo_db /var/www/html/xo_db
