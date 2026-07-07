package org.freegle.app.android.ui.components

import androidx.compose.material3.Icon
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.painterResource
import org.freegle.app.android.R

/**
 * Freegle heart-shaped recycling arrows icon, extracted from the official
 * Freegle SVG logo (user_logo_vector.svg). Uses an Android vector drawable
 * for crisp rendering at all sizes.
 */
@Composable
fun FreegleHeartArrows(
    modifier: Modifier = Modifier,
    color: Color = Color.White,
) {
    Icon(
        painter = painterResource(id = R.drawable.ic_freegle_arrows),
        contentDescription = "Give",
        modifier = modifier,
        tint = color,
    )
}
