// Freegle: scene-based life cycle. Apple requires this from the iOS 27 SDK onwards -
// an app built against that SDK with no scene manifest fails to launch. See TN3187.
//
// Once Info.plist declares UIApplicationSceneManifest, UIKit stops calling AppDelegate's
// application(_:open:options:), application(_:continue:...) and
// application(_:performActionFor:...), so the Google, Facebook, universal-link and
// app-icon quick-action handling that used to live there now lives here.
import UIKit
import Capacitor
import FBSDKCoreKit
import GoogleSignIn

class SceneDelegate: UIResponder, UIWindowSceneDelegate {
    // Set by UIKit from the scene manifest's UISceneStoryboardFile (Main), which is
    // what builds the CAPBridgeViewController root.
    var window: UIWindow?

    func scene(_ scene: UIScene, willConnectTo session: UISceneSession, options connectionOptions: UIScene.ConnectionOptions) {
        // A cold launch delivers its URL, universal link or quick action through
        // connectionOptions rather than the callbacks below, so every route has to be
        // drained here too or launching from one silently does nothing.
        for context in connectionOptions.urlContexts {
            handleOpenURL(context)
        }

        for userActivity in connectionOptions.userActivities {
            handleUserActivity(userActivity)
        }

        if let shortcutItem = connectionOptions.shortcutItem {
            handleShortcut(shortcutItem)
        }
    }

    func scene(_ scene: UIScene, openURLContexts urlContexts: Set<UIOpenURLContext>) {
        for context in urlContexts {
            handleOpenURL(context)
        }
    }

    func scene(_ scene: UIScene, continue userActivity: NSUserActivity) {
        handleUserActivity(userActivity)
    }

    func windowScene(_ windowScene: UIWindowScene, performActionFor shortcutItem: UIApplicationShortcutItem, completionHandler: @escaping (Bool) -> Void) {
        completionHandler(handleShortcut(shortcutItem))
    }

    @discardableResult
    private func handleOpenURL(_ context: UIOpenURLContext) -> Bool {
        let url = context.url

        if GIDSignIn.sharedInstance.handle(url) {
            return true
        }

        if FBSDKCoreKit.ApplicationDelegate.shared.application( // capacitor-community/facebook-login
            UIApplication.shared,
            open: url,
            sourceApplication: context.options.sourceApplication,
            annotation: context.options.annotation
        ) {
            return true
        }

        var options: [UIApplication.OpenURLOptionsKey: Any] = [:]
        if let sourceApplication = context.options.sourceApplication {
            options[.sourceApplication] = sourceApplication
        }
        if let annotation = context.options.annotation {
            options[.annotation] = annotation
        }

        return ApplicationDelegateProxy.shared.application(UIApplication.shared, open: url, options: options)
    }

    @discardableResult
    private func handleUserActivity(_ userActivity: NSUserActivity) -> Bool {
        // Universal Links. The proxy posts to Capacitor's listeners; nothing here
        // needs the restoration handler.
        return ApplicationDelegateProxy.shared.application(
            UIApplication.shared,
            continue: userActivity,
            restorationHandler: { _ in }
        )
    }

    // Long-press app-icon quick actions (UIApplicationShortcutItems in Info.plist).
    // Map each shortcut to a https://www.ilovefreegle.org/<path> URL and route it
    // through the same Capacitor pipeline as a deep link, so the appUrlOpen handler
    // (stores/mobile.js) navigates to the target page.
    @discardableResult
    private func handleShortcut(_ shortcutItem: UIApplicationShortcutItem) -> Bool {
        let path: String?
        switch shortcutItem.type {
        case "org.ilovefreegle.shortcut.give": path = "/give"
        case "org.ilovefreegle.shortcut.ask": path = "/ask"
        case "org.ilovefreegle.shortcut.chats": path = "/chats"
        default: path = nil
        }

        guard let path = path, let url = URL(string: "https://www.ilovefreegle.org" + path) else {
            return false
        }

        _ = ApplicationDelegateProxy.shared.application(UIApplication.shared, open: url, options: [:])
        return true
    }
}
