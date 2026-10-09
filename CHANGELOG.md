# Changelog

## 1.0.1

- Keep pending block writes and cached values when a chunk flush fails, so a later save can retry.
- Pin Axolotl-PM 5.49.1 for development and add LevelDB persistence tests, PHPStan level max, and source style checks to CI.
- Clarify the virion installation, runtime requirements, storage behavior, and existing LGPL license.

The public API and stored LevelDB key format are unchanged. To roll back, restore the previous virion source in the host plugin and rebuild its PHAR. Back up plugin data before deploying a different build.
