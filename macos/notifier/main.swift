// bb Notifier: posts one native macOS notification as "bb" (with bb's icon)
// and exits. bb launches it through LaunchServices (`open -g -a`):
//   bb-notifier -title <title> -message <message>
//
// It runs as a real (Dock-less) AppKit app: macOS only shows the "allow
// notifications" prompt to processes that check in as applications.
import AppKit
import UserNotifications

var title = "bb"
var message = ""
var args = CommandLine.arguments.dropFirst().makeIterator()

while let arg = args.next() {
    switch arg {
    case "-title": title = args.next() ?? title
    case "-message": message = args.next() ?? message
    default: break
    }
}

func fail(_ text: String, _ code: Int32) -> Never {
    FileHandle.standardError.write("bb Notifier: \(text)\n".data(using: .utf8)!)
    exit(code)
}

final class Delegate: NSObject, NSApplicationDelegate {
    func applicationDidFinishLaunching(_ notification: Notification) {
        let center = UNUserNotificationCenter.current()

        // The first call shows macOS's "Allow notifications from bb?" prompt.
        center.requestAuthorization(options: [.alert, .sound]) { granted, error in
            guard granted else {
                fail("notifications are not allowed (\(error?.localizedDescription ?? "denied"))", 3)
            }

            let content = UNMutableNotificationContent()
            content.title = title
            content.body = message
            content.sound = .default

            center.add(UNNotificationRequest(identifier: UUID().uuidString, content: content, trigger: nil)) { error in
                if let error { fail(error.localizedDescription, 4) }
                exit(0)
            }
        }

        // Never linger (e.g. the prompt left unanswered).
        DispatchQueue.main.asyncAfter(deadline: .now() + 60) { fail("timed out", 5) }
    }
}

let app = NSApplication.shared
let delegate = Delegate()
app.delegate = delegate
app.setActivationPolicy(.accessory)
app.run()
