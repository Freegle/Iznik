package org.freegle.app.android.ui.screens

import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.pager.HorizontalPager
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Chat
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import coil3.compose.AsyncImage
import kotlinx.coroutines.launch
import org.freegle.app.android.R

private data class OnboardingPage(
    val imageUrl: String,
    val title: String,
    val subtitle: String,
    val icon: ImageVector,
    val accentColor: Color,
    val showLogo: Boolean = false,
)

private val pages = listOf(
    OnboardingPage(
        imageUrl = "https://www.ilovefreegle.org/landingpage/Freegler1.jpeg",
        title = "Welcome to Freegle",
        subtitle = "Don\u2019t throw it away \u2013 give it away! Join people giving and getting things for free in your local community.",
        icon = Icons.Default.Favorite,
        accentColor = Color(0xFF008040),
        showLogo = true,
    ),
    OnboardingPage(
        imageUrl = "https://www.ilovefreegle.org/landingpage/Freegler5.jpeg",
        title = "Give stuff you don\u2019t need",
        subtitle = "Snap a photo, write a quick description, and your neighbours will see it. Someone nearby probably wants exactly what you\u2019re offering.",
        icon = Icons.Default.CardGiftcard,
        accentColor = Color(0xFF2196F3),
    ),
    OnboardingPage(
        imageUrl = "https://www.ilovefreegle.org/landingpage/Freegler10.jpeg",
        title = "Get things for free",
        subtitle = "Browse what\u2019s available near you, or post a Wanted \u2013 ask for something you need and your community will help.",
        icon = Icons.Default.Search,
        accentColor = Color(0xFF1565C0),
    ),
    OnboardingPage(
        imageUrl = "https://www.ilovefreegle.org/landingpage/Freegler15.jpeg",
        title = "People, not transactions",
        subtitle = "Freegle is a community, not a marketplace. You\u2019ll see real people\u2019s names and photos. Every item kept out of landfill matters.",
        icon = Icons.Default.Groups,
        accentColor = Color(0xFF9C27B0),
    ),
)

@OptIn(ExperimentalFoundationApi::class)
@Composable
fun OnboardingScreen(
    onComplete: () -> Unit,
) {
    val pagerState = rememberPagerState(pageCount = { pages.size })
    val scope = rememberCoroutineScope()
    val isLastPage = pagerState.currentPage == pages.size - 1

    Box(modifier = Modifier.fillMaxSize()) {
        HorizontalPager(
            state = pagerState,
            modifier = Modifier.fillMaxSize(),
        ) { pageIndex ->
            val page = pages[pageIndex]
            OnboardingPageContent(page = page)
        }

        // Bottom controls
        Column(
            modifier = Modifier
                .align(Alignment.BottomCenter)
                .fillMaxWidth()
                .padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            // Page indicators
            Row(
                horizontalArrangement = Arrangement.spacedBy(8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                pages.forEachIndexed { index, _ ->
                    val isSelected = pagerState.currentPage == index
                    val color by animateColorAsState(
                        if (isSelected) Color.White
                        else Color.White.copy(alpha = 0.4f),
                        label = "dot_$index",
                    )
                    Box(
                        modifier = Modifier
                            .size(if (isSelected) 10.dp else 8.dp)
                            .background(color, CircleShape),
                    )
                }
            }

            Spacer(Modifier.height(24.dp))

            // Next / Get Started button - white text on dark green for good contrast
            Button(
                onClick = {
                    if (isLastPage) {
                        onComplete()
                    } else {
                        scope.launch {
                            pagerState.animateScrollToPage(pagerState.currentPage + 1)
                        }
                    }
                },
                modifier = Modifier
                    .fillMaxWidth()
                    .height(52.dp),
                shape = RoundedCornerShape(26.dp),
                colors = ButtonDefaults.buttonColors(
                    containerColor = Color.White,
                    contentColor = Color(0xFF008040),
                ),
            ) {
                Text(
                    if (isLastPage) "Get started" else "Next",
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.Bold,
                )
            }

            if (!isLastPage) {
                Spacer(Modifier.height(8.dp))
                TextButton(onClick = onComplete) {
                    Text("Skip", color = Color.White.copy(alpha = 0.8f))
                }
            }
        }
    }
}

@Composable
private fun OnboardingPageContent(page: OnboardingPage) {
    Box(modifier = Modifier.fillMaxSize()) {
        // Background image
        AsyncImage(
            model = page.imageUrl,
            contentDescription = null,
            modifier = Modifier.fillMaxSize(),
            contentScale = ContentScale.Crop,
        )

        // Gradient overlay for readability
        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(
                    Brush.verticalGradient(
                        colors = listOf(
                            Color.Transparent,
                            Color.Black.copy(alpha = 0.3f),
                            Color.Black.copy(alpha = 0.85f),
                        ),
                        startY = 0f,
                        endY = Float.POSITIVE_INFINITY,
                    ),
                ),
        )

        // Content at bottom
        Column(
            modifier = Modifier
                .align(Alignment.BottomCenter)
                .fillMaxWidth()
                .padding(horizontal = 32.dp)
                .padding(bottom = 160.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            // Icon badge — show Freegle logo on welcome page, themed icon on others
            if (page.showLogo) {
                Image(
                    painter = painterResource(R.drawable.freegle_logo),
                    contentDescription = "Freegle",
                    modifier = Modifier
                        .size(72.dp)
                        .clip(RoundedCornerShape(16.dp)),
                )
            } else {
                Box(
                    modifier = Modifier
                        .size(64.dp)
                        .background(page.accentColor, CircleShape),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(
                        page.icon,
                        contentDescription = null,
                        modifier = Modifier.size(32.dp),
                        tint = Color.White,
                    )
                }
            }

            Spacer(Modifier.height(20.dp))

            Text(
                text = page.title,
                style = MaterialTheme.typography.headlineMedium,
                fontWeight = FontWeight.Bold,
                color = Color.White,
                textAlign = TextAlign.Center,
            )

            Spacer(Modifier.height(12.dp))

            Text(
                text = page.subtitle,
                style = MaterialTheme.typography.bodyLarge,
                color = Color.White.copy(alpha = 0.85f),
                textAlign = TextAlign.Center,
                lineHeight = MaterialTheme.typography.bodyLarge.lineHeight,
            )
        }
    }
}

/**
 * Feature tour using Dialog for proper touch handling.
 * Each step shows a Freegle community photo, icon, title, and description.
 */
private data class TourStep(
    val title: String,
    val description: String,
    val icon: ImageVector,
    val imageUrl: String,
)

@Composable
fun FeatureTourOverlay(
    currentStep: Int,
    onNext: () -> Unit,
    onSkip: () -> Unit,
) {
    val steps = listOf(
        TourStep(
            title = "Meet your community",
            description = "See what people near you are giving away. Tap any item to find out more and message the giver.",
            icon = Icons.Default.Home,
            imageUrl = "https://www.ilovefreegle.org/landingpage/Freegler3.jpeg",
        ),
        TourStep(
            title = "Explore your area",
            description = "Search and discover what\u2019s available in your neighbourhood.",
            icon = Icons.Default.Explore,
            imageUrl = "https://www.ilovefreegle.org/landingpage/Freegler7.jpeg",
        ),
        TourStep(
            title = "Give something away",
            description = "Tap the Give button to offer something you no longer need. It only takes a minute!",
            icon = Icons.Default.Add,
            imageUrl = "https://www.ilovefreegle.org/landingpage/Freegler12.jpeg",
        ),
        TourStep(
            title = "Chat with people",
            description = "Message givers directly to arrange collection. It\u2019s a friendly community!",
            icon = Icons.AutoMirrored.Filled.Chat,
            imageUrl = "https://www.ilovefreegle.org/landingpage/Freegler20.jpeg",
        ),
        TourStep(
            title = "Post a Wanted",
            description = "Can\u2019t find what you need? Post a Wanted and your neighbours will help out.",
            icon = Icons.Default.PostAdd,
            imageUrl = "https://www.ilovefreegle.org/landingpage/Freegler25.jpeg",
        ),
    )

    if (currentStep >= steps.size) return

    val step = steps[currentStep]

    // Use Dialog for proper touch handling (overlay Box doesn't intercept events properly)
    Dialog(
        onDismissRequest = onSkip,
        properties = DialogProperties(usePlatformDefaultWidth = false),
    ) {
        Card(
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 28.dp),
            shape = RoundedCornerShape(24.dp),
            colors = CardDefaults.cardColors(
                containerColor = MaterialTheme.colorScheme.surface,
            ),
            elevation = CardDefaults.cardElevation(defaultElevation = 8.dp),
        ) {
            Column {
                // Tour step image - Freegle community photo
                Box(
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(160.dp)
                        .clip(RoundedCornerShape(topStart = 24.dp, topEnd = 24.dp)),
                ) {
                    AsyncImage(
                        model = step.imageUrl,
                        contentDescription = null,
                        modifier = Modifier.fillMaxSize(),
                        contentScale = ContentScale.Crop,
                    )
                    // Gradient scrim at bottom of image
                    Box(
                        modifier = Modifier
                            .fillMaxWidth()
                            .height(60.dp)
                            .align(Alignment.BottomCenter)
                            .background(
                                Brush.verticalGradient(
                                    colors = listOf(
                                        Color.Transparent,
                                        MaterialTheme.colorScheme.surface,
                                    ),
                                ),
                            ),
                    )
                    // Icon badge overlapping image
                    Box(
                        modifier = Modifier
                            .align(Alignment.BottomCenter)
                            .offset(y = 24.dp)
                            .size(52.dp)
                            .background(MaterialTheme.colorScheme.primaryContainer, CircleShape),
                        contentAlignment = Alignment.Center,
                    ) {
                        Icon(
                            step.icon,
                            contentDescription = null,
                            modifier = Modifier.size(26.dp),
                            tint = MaterialTheme.colorScheme.primary,
                        )
                    }
                }

                Column(
                    modifier = Modifier.padding(horizontal = 24.dp).padding(top = 32.dp, bottom = 20.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    Text(
                        step.title,
                        style = MaterialTheme.typography.titleLarge,
                        fontWeight = FontWeight.Bold,
                        textAlign = TextAlign.Center,
                    )

                    Spacer(Modifier.height(8.dp))

                    Text(
                        step.description,
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        textAlign = TextAlign.Center,
                    )

                    Spacer(Modifier.height(8.dp))

                    Text(
                        "${currentStep + 1} of ${steps.size}",
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )

                    Spacer(Modifier.height(16.dp))

                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                    ) {
                        TextButton(onClick = onSkip) {
                            Text("Skip tour")
                        }
                        Button(
                            onClick = onNext,
                            colors = ButtonDefaults.buttonColors(
                                containerColor = MaterialTheme.colorScheme.primary,
                                contentColor = Color.White,
                            ),
                        ) {
                            Text(if (currentStep == steps.size - 1) "Done" else "Next")
                        }
                    }
                }
            }
        }
    }
}
