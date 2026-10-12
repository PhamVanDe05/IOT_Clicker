#!/bin/sh
set -eu
project_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
arduino_dir="$project_dir/.local/arduino"
export ARDUINO_DIRECTORIES_DATA="$arduino_dir/data"
export ARDUINO_DIRECTORIES_USER="$arduino_dir/sketchbook"
export ARDUINO_DIRECTORIES_DOWNLOADS="$arduino_dir/downloads"
export ARDUINO_BUILD_CACHE_PATH="$arduino_dir/cache"
exec "$HOME/Applications/arduino-ide_2.3.10_Linux_64bit.AppImage" --no-sandbox "${1:-$project_dir/firmware/SmartClickerESP32/SmartClickerESP32.ino}"
