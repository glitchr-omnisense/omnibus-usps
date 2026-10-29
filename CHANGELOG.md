# Changelog

## [Unreleased]

### Fixed
- Drop-off locations' opening hours are read by the day's name (`dropShipAcceptanceTimes`,
  `dayOfWeek` MONDAY to SUNDAY, as USPS's published example answers) as ISO weekdays with every
  `openTime`/`closeTime` slot in `HH:MM`; they were assigned by their position (Monday assumed
  first) and a day without hours kept an empty `['', '']` slot. The location's `facilityAddress`
  and `dropShipmentKey` are read too.
