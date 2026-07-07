package org.freegle.app.android.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp
import androidx.compose.ui.text.googlefonts.GoogleFont
import androidx.compose.ui.text.googlefonts.Font
import androidx.compose.ui.text.font.FontFamily
import org.freegle.app.android.R

// Warm, community-feel typography using Nunito (rounded letterforms)
private val fontProvider = GoogleFont.Provider(
    providerAuthority = "com.google.android.gms.fonts",
    providerPackage = "com.google.android.gms",
    certificates = R.array.com_google_android_gms_fonts_certs,
)

private val nunitoFont = GoogleFont("Nunito")

private val NunitoFontFamily = FontFamily(
    Font(googleFont = nunitoFont, fontProvider = fontProvider, weight = FontWeight.Normal),
    Font(googleFont = nunitoFont, fontProvider = fontProvider, weight = FontWeight.Medium),
    Font(googleFont = nunitoFont, fontProvider = fontProvider, weight = FontWeight.SemiBold),
    Font(googleFont = nunitoFont, fontProvider = fontProvider, weight = FontWeight.Bold),
)

private val FreegleTypography = Typography(
    bodyLarge = TextStyle(fontFamily = NunitoFontFamily, fontWeight = FontWeight.Normal, fontSize = 16.sp, lineHeight = 24.sp),
    bodyMedium = TextStyle(fontFamily = NunitoFontFamily, fontWeight = FontWeight.Normal, fontSize = 14.sp, lineHeight = 20.sp),
    bodySmall = TextStyle(fontFamily = NunitoFontFamily, fontWeight = FontWeight.Normal, fontSize = 12.sp, lineHeight = 16.sp),
    titleLarge = TextStyle(fontFamily = NunitoFontFamily, fontWeight = FontWeight.SemiBold, fontSize = 22.sp, lineHeight = 28.sp),
    titleMedium = TextStyle(fontFamily = NunitoFontFamily, fontWeight = FontWeight.SemiBold, fontSize = 16.sp, lineHeight = 24.sp),
    titleSmall = TextStyle(fontFamily = NunitoFontFamily, fontWeight = FontWeight.Medium, fontSize = 14.sp, lineHeight = 20.sp),
    labelLarge = TextStyle(fontFamily = NunitoFontFamily, fontWeight = FontWeight.SemiBold, fontSize = 14.sp, lineHeight = 20.sp),
    labelMedium = TextStyle(fontFamily = NunitoFontFamily, fontWeight = FontWeight.SemiBold, fontSize = 12.sp, lineHeight = 16.sp),
    labelSmall = TextStyle(fontFamily = NunitoFontFamily, fontWeight = FontWeight.Medium, fontSize = 11.sp, lineHeight = 16.sp),
)

// Always use Freegle brand colours - never dynamic/wallpaper colours
private val FreegleLightColorScheme = lightColorScheme(
    primary = FreegleGreen,
    onPrimary = WarmCard,
    primaryContainer = GreenContainer,
    onPrimaryContainer = OnGreenContainer,
    secondary = FreegleGreenDark,
    onSecondary = WarmCard,
    secondaryContainer = GreenContainer,
    onSecondaryContainer = OnGreenContainer,
    tertiary = FreegleBlue,
    onTertiary = WarmCard,
    tertiaryContainer = BlueContainer,
    onTertiaryContainer = OnBlueContainer,
    error = FreegleError,
    background = WarmWhite,
    onBackground = WarmOnSurface,
    surface = WarmSurface,
    onSurface = WarmOnSurface,
    surfaceVariant = WarmSurfaceVariant,
    onSurfaceVariant = WarmOnSurfaceVariant,
    surfaceContainerLowest = WarmCard,
    surfaceContainerLow = WarmWhite,
    surfaceContainer = WarmSurface,
    surfaceContainerHigh = WarmSurfaceVariant,
    outline = Color(0xFFB0A89E),
    outlineVariant = Color(0xFFD4CEC6),
)

private val FreegleDarkColorScheme = darkColorScheme(
    primary = FreegleGreenLight,
    onPrimary = OnGreenContainer,
    primaryContainer = FreegleGreenDark,
    onPrimaryContainer = GreenContainer,
    secondary = FreegleGreenLight,
    onSecondary = OnGreenContainer,
    secondaryContainer = FreegleGreenDark,
    onSecondaryContainer = GreenContainer,
    tertiary = FreegleBlue,
    onTertiary = OnBlueContainer,
    tertiaryContainer = Color(0xFF0D47A1),
    onTertiaryContainer = BlueContainer,
    error = Color(0xFFFF8A80),
    background = DarkBackground,
    onBackground = Color(0xFFE8E2DC),
    surface = DarkSurface,
    onSurface = Color(0xFFE8E2DC),
    surfaceVariant = DarkSurfaceVariant,
    onSurfaceVariant = Color(0xFFC8C2BA),
    surfaceContainerLowest = Color(0xFF141312),
    surfaceContainerLow = DarkBackground,
    surfaceContainer = DarkSurface,
    surfaceContainerHigh = DarkSurfaceVariant,
    outline = Color(0xFF8A8480),
    outlineVariant = Color(0xFF4D4843),
)

@Composable
fun FreegleTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit,
) {
    val colorScheme = if (darkTheme) FreegleDarkColorScheme else FreegleLightColorScheme

    MaterialTheme(
        colorScheme = colorScheme,
        typography = FreegleTypography,
        content = content,
    )
}
