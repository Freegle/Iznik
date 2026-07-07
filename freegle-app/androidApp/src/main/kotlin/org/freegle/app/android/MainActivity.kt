package org.freegle.app.android

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import org.freegle.app.android.ui.theme.FreegleTheme
import org.freegle.app.android.ui.navigation.FreegleNavHost

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            FreegleTheme {
                FreegleNavHost()
            }
        }
    }
}
