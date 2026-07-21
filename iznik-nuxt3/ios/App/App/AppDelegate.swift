// Freegle: changes made as per https://github.com/Cap-go/capacitor-social-login?tab=readme-ov-file#ios-configuration-1
import UIKit
import Capacitor
import FBSDKCoreKit // capacitor-community/facebook-login
import Firebase // @capacitor/push-notifications https://devdactic.com/push-notifications-ionic-capacitor

@UIApplicationMain
class AppDelegate: UIResponder, UIApplicationDelegate {

    // The window and the per-scene life cycle live in SceneDelegate now - see the
    // UIApplicationSceneManifest entry in Info.plist.

    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?) -> Bool {
        // Override point for customization after application launch.
        FBSDKCoreKit.ApplicationDelegate.shared.application( // capacitor-community/facebook-login
            application,
            didFinishLaunchingWithOptions: launchOptions
        )
        FirebaseApp.configure()
        return true
    }

    // The empty applicationWillResignActive / DidEnterBackground / WillEnterForeground /
    // DidBecomeActive / WillTerminate stubs are gone: UIKit does not call them in a
    // scene-based app. Their scene equivalents belong in SceneDelegate.
    //
    // application(_:open:options:), application(_:continue:restorationHandler:) and
    // application(_:performActionFor:completionHandler:) have moved to SceneDelegate too,
    // for the same reason. Push registration below is still app-level and stays here.

    func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
        Messaging.messaging().apnsToken = deviceToken
        Messaging.messaging().token { (token, error) in
            if let error = error {
                NotificationCenter.default.post(name: Notification.Name(CAPNotifications.DidFailToRegisterForRemoteNotificationsWithError.name()), object: error)
            } else if let token = token {
                NotificationCenter.default.post(name: Notification.Name(CAPNotifications.DidRegisterForRemoteNotificationsWithDeviceToken.name()), object: token)
            }
        }
    }

}
