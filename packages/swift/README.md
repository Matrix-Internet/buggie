# Buggie for iOS

Report bugs from an iOS app into [Buggie](https://buggie.eu), carrying the same
context the web widget does: recent logs, recent requests, the last error, a
screenshot, and what the device can say about itself.

Requires iOS 15. AGPL-3.0, like the rest of Buggie.

## Install

**Not published as its own repository yet.** Swift Package Manager only resolves a
package whose `Package.swift` is at the root of a repository, and this one lives at
`packages/swift` in the [main Buggie repository](https://github.com/bpowerie25/buggie).
A `buggie-swift` mirror will fix that; until it exists, use it as a local package:

1. Clone the Buggie repository.
2. In Xcode, **File → Add Package Dependencies… → Add Local…**, and choose its
   `packages/swift` folder.
3. Add `Buggie` to your target's dependencies.

Or from another package's `Package.swift`:

```swift
.package(path: "../buggie/packages/swift")
```

## Use

```swift
import Buggie

// Once, at launch. The key is the public widget key from project settings.
Buggie.shared.start(key: "pk_live_…")

// Self-hosting:
Buggie.shared.start(key: "pk_live_…", endpoint: URL(string: "https://bugs.acme.com")!)
```

Then present your own "report a bug" UI and send it:

```swift
let screenshot = Buggie.shared.captureScreen()

try await Buggie.shared.report(
    title: "Pay now does nothing",
    body: notes,
    reporterEmail: email,
    screenshot: screenshot
)
```

There is no built-in report sheet. What a bug report looks like inside your app is
your decision, and a sheet we shipped would be the first thing you had to fight.

### Context

The more of this you feed it, the less a report is a guess.

```swift
Buggie.shared.identify(id: user.id, email: user.email, name: user.name)
Buggie.shared.setRelease("2.4.1")

Buggie.shared.leaveBreadcrumb("Tapped Pay now")
Buggie.shared.leaveBreadcrumb("Card declined", level: .error)

Buggie.shared.record(method: "POST", url: url, status: 500, duration: 0.41)

do { try pay() } catch { Buggie.shared.record(error: error) }
```

Breadcrumbs and requests are kept in fixed-size buffers — the last 50 and 30 — so a
long session cannot grow without bound.

## What is never sent

- **Request headers and bodies.** Only method, URL, status and duration.
- **Credentials in URLs.** Query parameters named like secrets (`token`, `api_key`,
  `password`, `code`, and others) have their values replaced, and URL fragments are
  dropped entirely, because OAuth flows park tokens there.
- **Anything you did not hand over.** Identity is what you passed to `identify`.
  Nothing is scraped.

### Screenshots

Mark anything private and it is hidden **before** the screen is rendered, not painted
over afterwards:

```swift
cardNumberField.buggieRedacted = true
```

`UITextField`s with `isSecureTextEntry` are redacted without you having to remember.

Views are hidden before rendering for the same reason the web widget restyles the DOM
instead of drawing on the finished canvas: a mask positioned by separate arithmetic
can be silently misaligned, and a redaction that can be silently misaligned is not a
redaction. This was a real bug in the web widget before it was a rule here.

## Layout

| Target | What it is |
| --- | --- |
| `BuggieCore` | The payload, redaction, buffers, transport. No UIKit. Unit tested. |
| `Buggie` | Screen capture and device details. Needs a device. |

The split is so that the part that decides what leaves a phone can be tested on any
machine, rather than only where an iOS simulator exists.

## Tests

```sh
./test.sh
```

With Xcode installed, plain `swift test` works. With only the Command Line Tools the
script points the compiler at swift-testing, builds for macOS and runs `BuggieCore`;
the UIKit layer is excluded by `#if canImport(UIKit)` and needs Xcode to compile.
