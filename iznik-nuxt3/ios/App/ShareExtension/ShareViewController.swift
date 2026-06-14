//
//  ShareViewController.swift
//  ShareExtension
//
//  "Share an image into Freegle" — iOS share-sheet extension. When the user
//  shares one or more images to Freegle from Photos / another app, this
//  extension copies them into the shared App Group container and opens the host
//  app with a freegleshare://shared?p=<path>... URL. The host app's appUrlOpen
//  handler (stores/mobile.js) then starts an OFFER with the photo(s) attached.
//
//  No storyboard: NSExtensionPrincipalClass in Info.plist points straight here.
//  Kept deliberately small — there is no compose UI; we grab the image and hand
//  straight off to the app, mirroring the Android ACTION_SEND flow.
//

import UIKit
import UniformTypeIdentifiers

class ShareViewController: UIViewController {
    // Must match the App Group registered in the Apple Developer portal and the
    // entitlements of BOTH this extension and the main app.
    private let appGroupId = "group.org.ilovefreegle.iphone"
    private let urlScheme = "freegleshare"

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = .clear
        processShare()
    }

    private func processShare() {
        guard let items = extensionContext?.inputItems as? [NSExtensionItem] else {
            finish([])
            return
        }

        let imageType = UTType.image.identifier // "public.image"
        let group = DispatchGroup()
        let lock = NSLock()
        var paths: [String] = []

        for item in items {
            for provider in item.attachments ?? [] {
                guard provider.hasItemConformingToTypeIdentifier(imageType) else { continue }
                group.enter()
                provider.loadItem(forTypeIdentifier: imageType, options: nil) { data, _ in
                    defer { group.leave() }
                    var saved: String?
                    if let url = data as? URL {
                        saved = self.copyImage(from: url)
                    } else if let image = data as? UIImage {
                        saved = self.saveImageData(image.jpegData(compressionQuality: 0.9))
                    } else if let raw = data as? Data {
                        saved = self.saveImageData(raw)
                    }
                    if let saved = saved {
                        lock.lock()
                        paths.append(saved)
                        lock.unlock()
                    }
                }
            }
        }

        group.notify(queue: .main) {
            self.finish(paths)
        }
    }

    private func sharedDir() -> URL? {
        guard let base = FileManager.default.containerURL(
            forSecurityApplicationGroupIdentifier: appGroupId
        ) else { return nil }
        let dir = base.appendingPathComponent("SharedImages", isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir
    }

    private func uniqueName(ext: String) -> String {
        let stamp = Int(Date().timeIntervalSince1970 * 1000)
        return "share-\(stamp)-\(UUID().uuidString).\(ext)"
    }

    private func copyImage(from src: URL) -> String? {
        guard let dir = sharedDir() else { return nil }
        let ext = src.pathExtension.isEmpty ? "jpg" : src.pathExtension
        let dest = dir.appendingPathComponent(uniqueName(ext: ext))
        do {
            if FileManager.default.fileExists(atPath: dest.path) {
                try FileManager.default.removeItem(at: dest)
            }
            try FileManager.default.copyItem(at: src, to: dest)
            return dest.path
        } catch {
            return nil
        }
    }

    private func saveImageData(_ data: Data?) -> String? {
        guard let data = data, let dir = sharedDir() else { return nil }
        let dest = dir.appendingPathComponent(uniqueName(ext: "jpg"))
        do {
            try data.write(to: dest)
            return dest.path
        } catch {
            return nil
        }
    }

    private func finish(_ paths: [String]) {
        if !paths.isEmpty, let url = buildURL(paths) {
            openHostApp(url)
        }
        extensionContext?.completeRequest(returningItems: [], completionHandler: nil)
    }

    private func buildURL(_ paths: [String]) -> URL? {
        var comps = URLComponents()
        comps.scheme = urlScheme
        comps.host = "shared"
        comps.queryItems = paths.map { URLQueryItem(name: "p", value: $0) }
        return comps.url
    }

    // Open the host app from an extension. App extensions can't call
    // UIApplication.shared.open directly, so we walk the responder chain to the
    // UIApplication and invoke openURL: — the long-established technique for
    // share extensions that hand off to their container app.
    private func openHostApp(_ url: URL) {
        let selector = sel_registerName("openURL:")
        var responder: UIResponder? = self
        while let r = responder {
            if r != self, r.responds(to: selector) {
                r.perform(selector, with: url)
                return
            }
            responder = r.next
        }
    }
}
