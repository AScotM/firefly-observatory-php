# Firefly Observatory — PHP Edition

A native PHP 8.2+ CLI port of Firefly Observatory, based on the optimized Go simulation.

## Requirements

- PHP 8.2 or later, CLI SAPI
- Linux terminal with ANSI truecolor support for animated mode
- No Composer packages or nonstandard PHP extensions

## Quick start

```bash
php -l main.php
php main.php version
php main.php modes
php main.php palettes
php main.php --mode flock --palette aurora
```

Stop animation with Ctrl+C. The program hides the cursor while rendering and restores it on shutdown.

## Low-resource configuration

```bash
php main.php --mode breathe --palette moon --agents 120 --fps 12 --eco
```

## Headless rendering and exports

```bash
php main.php render --mode orbit --palette violet --agents 180 \
  --width 100 --height 32 --frames 180 \
  --output orbit.ppm --snapshot orbit.json --text orbit.txt
```

PPM is binary P6 RGB. Convert with `magick orbit.ppm orbit.png` if ImageMagick is installed.

```bash
php main.php inspect --frames 300 --agents 150 --record frames.jsonl
```

## Restoring a saved world

```bash
php main.php render --restore orbit.json --frames 400 --output resumed.ppm
```

Snapshots restore agent state, frame and simulation time, but not the original RNG stream or accumulated framebuffer trails. Restoring is not a bit-for-bit replay. The `--frames` limit refers to the absolute frame counter.

## Options

Run `php main.php --help` for the complete CLI reference.

Modes: flock, drift, orbit, breathe, swarm, rain.

Palettes: aurora, ember, moon, forest, ocean, violet, mono.

Controls: coupling, radius, speed, noise, trails, bloom, density, wind, gravity, seed, FPS, dimensions, frame count, duration, telemetry, ASCII rendering, exports, snapshots and recording.

## Implementation

The code preserves the Go edition's toroidal world, 3×3 spatial-neighborhood search, Kuramoto-like phase coupling, mode-specific forces, Gaussian glow, trail fading and RGB palettes. PHP arrays hold agents and framebuffers. Optional Linux telemetry reads `/proc/stat`, `/proc/meminfo` and `/proc/diskstats`.

PHP's random number generation and memory layout differ from Go, so the trajectories and performance are not identical. This is an algorithmic and visual port.

## Verification

```bash
php -l main.php
php main.php render --agents 20 --width 40 --height 15 \
  --frames 4 --output test.ppm --snapshot test.json --text test.txt
php -r 'json_decode(file_get_contents("test.json"), true, 512, JSON_THROW_ON_ERROR); echo "JSON OK\n";'
file test.ppm
```

## License

GPL-3.0-only. See LICENSE.
