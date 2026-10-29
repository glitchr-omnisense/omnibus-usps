# Changelog

## [Unreleased]

### Fixed
- A `CarrierException` no longer carries the client secret, the token and the payment authorization.
  The message quoted the HTTP client's (the whole URL), the previous was the client's own exception,
  and every trace of the chain kept the arguments of the calls under it (the request's options, the
  `Api` with its credentials) when `zend.exception_ignore_args` is off: a log of the whole chain
  wrote them. The message masks them as `***`, the previous is a copy of the client's exception (the
  same class and code), and no exception `Api` throws keeps its trace's arguments
  (`Omnibus\Http\Redaction`).
- Drop-off locations' opening hours are read by the day's name (`dropShipAcceptanceTimes`,
  `dayOfWeek` MONDAY to SUNDAY, as USPS's published example answers) as ISO weekdays with every
  `openTime`/`closeTime` slot in `HH:MM`; they were assigned by their position (Monday assumed
  first) and a day without hours kept an empty `['', '']` slot. The location's `facilityAddress`
  and `dropShipmentKey` are read too.
- A label's format is its image type's media type (`application/pdf`, `image/tiff`, `image/jpeg`,
  `image/png`, `image/gif`, `image/svg+xml`, `application/zpl`); any `label_format` other than PDF
  was labelled `application/zpl`. `ZPL` now asks for `ZPL203DPI`, the name USPS's Labels API gives
  it.
