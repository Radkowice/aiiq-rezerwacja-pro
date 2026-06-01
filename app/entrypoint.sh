#!/bin/sh
set -e

if [ "$#" -eq 0 ]; then
  exec nginx -g 'daemon off;'
fi

exec "$@"
