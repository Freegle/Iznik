package org.freegle.blockstore;

import com.getcapacitor.JSObject;
import com.getcapacitor.Logger;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.google.android.gms.auth.blockstore.Blockstore;
import com.google.android.gms.auth.blockstore.BlockstoreClient;
import com.google.android.gms.auth.blockstore.DeleteBytesRequest;
import com.google.android.gms.auth.blockstore.RetrieveBytesRequest;
import com.google.android.gms.auth.blockstore.RetrieveBytesResponse;
import com.google.android.gms.auth.blockstore.StoreBytesData;

import org.json.JSONObject;

import java.nio.charset.StandardCharsets;
import java.util.Collections;
import java.util.Map;

/**
 * Carries the login session to a new device, via Google Block Store.
 *
 * Google Play requires apps with sign-in to restore the session when a user moves to a new
 * Android device (Zero-Tap Sign-In restoration, enforced from April 2027). A Block Store
 * integration shipped on or before 30 September 2026 counts as compliant, which is the route
 * taken here: the alternative, the Restore Credentials API, is WebAuthn-shaped and would need
 * challenge issuance and assertion verification the Freegle API does not have.
 *
 * What travels is the `persistent` token the web layer already holds (see stores/auth.js) -
 * Block Store is a 4KB-per-entry key/value store that Android hands to the new device during
 * setup, so nothing server-side changes.
 *
 * This plugin lives in a package of its own rather than the app's, because the ModTools build
 * rewrites org.ilovefreegle.direct -> org.ilovefreegle.modtools in MainActivity's package line;
 * a same-package reference would compile for Freegle and fail for ModTools.
 */
@CapacitorPlugin(name = "BlockStore")
public class BlockStorePlugin extends Plugin {

  // Our own key, so a future second entry (16 are allowed) does not collide.
  private static final String KEY = "org.freegle.session";

  private BlockstoreClient client() {
    return Blockstore.getClient(getContext());
  }

  /**
   * Store the session blob. Cloud backup is enabled only when Block Store reports
   * end-to-end encryption available (the device has a screen lock): without it, the blob
   * would sit on Google's servers merely encrypted at rest, and this one is a credential.
   * With cloud backup off, direct device-to-device transfer still carries it.
   */
  @PluginMethod
  public void setSession(PluginCall call) {
    final String value = call.getString("value");

    if (value == null || value.isEmpty()) {
      call.reject("No value to store");
      return;
    }

    final byte[] bytes = value.getBytes(StandardCharsets.UTF_8);

    if (bytes.length > BlockstoreClient.MAX_SIZE) {
      call.reject("Session is " + bytes.length + " bytes, over the Block Store limit of " + BlockstoreClient.MAX_SIZE);
      return;
    }

    client()
      .isEndToEndEncryptionAvailable()
      .addOnSuccessListener(e2ee -> store(call, bytes, Boolean.TRUE.equals(e2ee)))
      .addOnFailureListener(e -> {
        // Treat an unanswerable check as "not encrypted" rather than failing the save.
        Logger.warn("BlockStore", "E2EE check failed, storing without cloud backup: " + e.getMessage());
        store(call, bytes, false);
      });
  }

  private void store(PluginCall call, byte[] bytes, boolean cloudBackup) {
    StoreBytesData data = new StoreBytesData.Builder()
      .setBytes(bytes)
      .setKey(KEY)
      .setShouldBackupToCloud(cloudBackup)
      .build();

    client()
      .storeBytes(data)
      .addOnSuccessListener(written -> {
        JSObject ret = new JSObject();
        ret.put("saved", true);
        ret.put("cloudBackup", cloudBackup);
        ret.put("bytes", written);
        call.resolve(ret);
      })
      .addOnFailureListener(e -> call.reject("Block Store write failed: " + e.getMessage(), e));
  }

  /**
   * Read the session blob back. Resolves with a null value rather than rejecting when there
   * is nothing stored or Block Store is unavailable: this runs on every cold start, where
   * "no session" is the ordinary case and boot must not have to catch.
   */
  @PluginMethod
  public void getSession(PluginCall call) {
    RetrieveBytesRequest request = new RetrieveBytesRequest.Builder()
      .setKeys(Collections.singletonList(KEY))
      .build();

    client()
      .retrieveBytes(request)
      .addOnSuccessListener(response -> {
        JSObject ret = new JSObject();
        Map<String, RetrieveBytesResponse.BlockstoreData> stored = response.getBlockstoreDataMap();
        RetrieveBytesResponse.BlockstoreData data = stored == null ? null : stored.get(KEY);
        byte[] bytes = data == null ? null : data.getBytes();

        ret.put("value", bytes == null || bytes.length == 0 ? JSONObject.NULL : new String(bytes, StandardCharsets.UTF_8));
        call.resolve(ret);
      })
      .addOnFailureListener(e -> {
        JSObject ret = new JSObject();
        ret.put("value", JSONObject.NULL);
        ret.put("error", e.getMessage());
        call.resolve(ret);
      });
  }

  /**
   * Drop the stored session. Called on logout - otherwise a device restore would sign a
   * user back in after they deliberately signed out.
   */
  @PluginMethod
  public void clearSession(PluginCall call) {
    DeleteBytesRequest request = new DeleteBytesRequest.Builder()
      .setKeys(Collections.singletonList(KEY))
      .build();

    client()
      .deleteBytes(request)
      .addOnSuccessListener(deleted -> {
        JSObject ret = new JSObject();
        ret.put("cleared", Boolean.TRUE.equals(deleted));
        call.resolve(ret);
      })
      .addOnFailureListener(e -> call.reject("Block Store delete failed: " + e.getMessage(), e));
  }
}
