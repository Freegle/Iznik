package org.ilovefreegle.direct;

import android.util.Log;
import android.webkit.WebView;
import android.webkit.JavascriptInterface;
import com.getcapacitor.BridgeActivity;
import org.freegle.blockstore.BlockStorePlugin;
import android.os.Bundle;
import ee.forgr.capacitor.social.login.GoogleProvider;
import ee.forgr.capacitor.social.login.SocialLoginPlugin;
import ee.forgr.capacitor.social.login.ModifiedMainActivityForSocialLoginPlugin;
import com.getcapacitor.PluginHandle;
import com.getcapacitor.Plugin;
import android.content.Intent;
import android.net.Uri;
import org.json.JSONArray;
import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.util.ArrayList;

public class MainActivity extends BridgeActivity implements ModifiedMainActivityForSocialLoginPlugin {

  // Absolute paths of images shared into the app (ACTION_SEND) but not yet handed
  // to the WebView. The web layer pulls them via window.FreegleShare.consume() on
  // startup and on every resume, then starts an OFFER with them pre-attached.
  private final ArrayList<String> pendingSharedImages = new ArrayList<>();

  // Marks an intent whose images we have already copied. On a cold-start share the
  // same intent arrives twice: Capacitor's BridgeActivity.load() (reached from
  // super.onCreate) hands the launch intent to onNewIntent() itself, and then our
  // own onCreate calls handleSendIntent(getIntent()) as well. Without this flag the
  // image was copied twice and the Offer opened with two identical photos. We keep
  // both call sites - a future Capacitor may stop re-delivering the launch intent -
  // and make the handling idempotent instead.
  private static final String EXTRA_SHARE_HANDLED = "org.ilovefreegle.direct.SHARE_HANDLED";

  public void onCreate(Bundle savedInstanceState) {
    //Log.e("PHDCC","org.ilovefreegle.direct onCreate A");
    // Plugins declared in this project (rather than pulled from node_modules, which
    // capacitor.plugins.json registers for us) have to be registered before the bridge is
    // built in super.onCreate.
    registerPlugin(BlockStorePlugin.class);

    super.onCreate(savedInstanceState);

    // Enable WebView debugging for chrome://inspect only in debuggable builds
    if ((getApplicationInfo().flags & android.content.pm.ApplicationInfo.FLAG_DEBUGGABLE) != 0) {
      WebView.setWebContentsDebuggingEnabled(true);
      Log.d("MainActivity", "WebView debugging enabled for chrome://inspect");
    }

    // Expose a tiny bridge so the WebView can pull images shared into the app.
    // The WebView only ever loads our own bundle, so a named interface is safe.
    WebView webView = getBridge() != null ? getBridge().getWebView() : null;
    if (webView != null) {
      webView.addJavascriptInterface(new ShareIntentBridge(), "FreegleShare");
    }

    handleSendIntent(getIntent());
  }

  @Override
  protected void onNewIntent(Intent intent) {
    super.onNewIntent(intent);
    // Keep getIntent() current so a later cold-start path sees the right intent.
    setIntent(intent);
    handleSendIntent(intent);
  }

  // Capture an image (or images) shared into Freegle from another app. We copy
  // each one into our cache because the content:// read permission is scoped to
  // this intent; the cache file is then readable by the WebView later via
  // Capacitor.convertFileSrc().
  private void handleSendIntent(Intent intent) {
    if (intent == null) return;
    String action = intent.getAction();
    String type = intent.getType();
    if (action == null || type == null || !type.startsWith("image/")) return;

    // Copy each shared intent's images exactly once, however many times the intent
    // is delivered to us (see EXTRA_SHARE_HANDLED).
    if (intent.getBooleanExtra(EXTRA_SHARE_HANDLED, false)) return;
    intent.putExtra(EXTRA_SHARE_HANDLED, true);

    try {
      if (Intent.ACTION_SEND.equals(action)) {
        Uri uri = intent.getParcelableExtra(Intent.EXTRA_STREAM);
        if (uri != null) copySharedImage(uri);
      } else if (Intent.ACTION_SEND_MULTIPLE.equals(action)) {
        ArrayList<Uri> uris = intent.getParcelableArrayListExtra(Intent.EXTRA_STREAM);
        if (uris != null) {
          for (Uri uri : uris) {
            if (uri != null) copySharedImage(uri);
          }
        }
      }
    } catch (Exception e) {
      Log.e("MainActivity", "Failed to handle shared image", e);
    }
  }

  private void copySharedImage(Uri uri) {
    try {
      File dir = new File(getCacheDir(), "shared");
      if (!dir.exists()) dir.mkdirs();
      File out;
      synchronized (pendingSharedImages) {
        out = new File(dir, "share-" + System.currentTimeMillis() + "-" + pendingSharedImages.size() + ".jpg");
      }
      try (InputStream in = getContentResolver().openInputStream(uri);
           OutputStream os = new FileOutputStream(out)) {
        if (in == null) return;
        byte[] buf = new byte[8192];
        int n;
        while ((n = in.read(buf)) > 0) os.write(buf, 0, n);
      }
      synchronized (pendingSharedImages) {
        pendingSharedImages.add(out.getAbsolutePath());
      }
      Log.d("MainActivity", "Stored shared image " + out.getAbsolutePath());
    } catch (Exception e) {
      Log.e("MainActivity", "Failed to copy shared image", e);
    }
  }

  // window.FreegleShare.consume() returns a JSON array of absolute file paths for
  // images shared into the app and clears the queue. The web layer converts each
  // with Capacitor.convertFileSrc() and feeds it into the give-flow uploader.
  public class ShareIntentBridge {
    @JavascriptInterface
    public String consume() {
      JSONArray arr = new JSONArray();
      synchronized (pendingSharedImages) {
        for (String p : pendingSharedImages) arr.put(p);
        pendingSharedImages.clear();
      }
      return arr.toString();
    }
  }

  @Override
  public void onActivityResult(int requestCode, int resultCode, Intent data) {
    super.onActivityResult(requestCode, resultCode, data);
    
    if (requestCode >= GoogleProvider.REQUEST_AUTHORIZE_GOOGLE_MIN && requestCode < GoogleProvider.REQUEST_AUTHORIZE_GOOGLE_MAX) {
      PluginHandle pluginHandle = getBridge().getPlugin("SocialLogin");
      if (pluginHandle == null) {
        Log.i("Google Activity Result", "SocialLogin login handle is null");
        return;
      }
      Plugin plugin = pluginHandle.getInstance();
      if (!(plugin instanceof SocialLoginPlugin)) {
        Log.i("Google Activity Result", "SocialLogin plugin instance is not SocialLoginPlugin");
        return;
      }
      ((SocialLoginPlugin) plugin).handleGoogleLoginIntent(requestCode, data);
    }
  }

  public void IHaveModifiedTheMainActivityForTheUseWithSocialLoginPlugin() {}
}
