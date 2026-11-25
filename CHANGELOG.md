# Changelog

## 0.1.1

- Speed up `Exact` mapping without aliases by reusing validated fillable keys.
- Add regression coverage for alias collisions and for preserving existing top-level and nested PHP references in prepared arrays, validated Form Request data, and explicit additions.
- Measure validated Form Request input capture separately from request body selection in the benchmark.

## 0.1.0

Initial release.
