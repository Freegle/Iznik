package org.ilovefreegle.direct;

import android.util.Log;
import android.webkit.WebView;
import com.getcapacitor.BridgeActivity;
import android.os.Bundle;

// ModTools variant of MainActivity — no social login plugin (not used in ModTools)
public class MainActivity extends BridgeActivity {

    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        if ((getApplicationInfo().flags & android.content.pm.ApplicationInfo.FLAG_DEBUGGABLE) != 0) {
            WebView.setWebContentsDebuggingEnabled(true);
            Log.d("MainActivity", "WebView debugging enabled for chrome://inspect");
        }
    }
}
