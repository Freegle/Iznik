package org.freegle.app.android.ui.screens

import androidx.compose.animation.*
import androidx.compose.animation.core.*
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.*
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

private enum class GiveStep { TYPE, PHOTO, TITLE, LOCATION, DONE }

@Composable
fun GiveScreen(
    userPostcode: String = "",
    userLocationName: String = "",
    onLocationChange: (() -> Unit)? = null,
) {
    var step by remember { mutableStateOf(GiveStep.TYPE) }
    var isOffer by remember { mutableStateOf(true) }
    var title by remember { mutableStateOf("") }
    var description by remember { mutableStateOf("") }
    var hasPhoto by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()

    // Step direction for slide animation
    var goingForward by remember { mutableStateOf(true) }

    val stepIndex = when (step) {
        GiveStep.TYPE -> 0
        GiveStep.PHOTO -> 1
        GiveStep.TITLE -> 2
        GiveStep.LOCATION -> 3
        GiveStep.DONE -> 4
    }

    fun advance(nextStep: GiveStep) {
        goingForward = true
        step = nextStep
    }

    fun back(prevStep: GiveStep) {
        goingForward = false
        step = prevStep
    }

    Box(modifier = Modifier.fillMaxSize()) {
        AnimatedContent(
            targetState = step,
            transitionSpec = {
                if (goingForward) {
                    (slideInHorizontally { it } + fadeIn(tween(220))).togetherWith(
                        slideOutHorizontally { -it / 2 } + fadeOut(tween(180))
                    )
                } else {
                    (slideInHorizontally { -it } + fadeIn(tween(220))).togetherWith(
                        slideOutHorizontally { it / 2 } + fadeOut(tween(180))
                    )
                }
            },
            label = "giveStep",
        ) { currentStep ->
            when (currentStep) {
                GiveStep.TYPE -> TypeStep(
                    isOffer = isOffer,
                    onChoose = { offer ->
                        isOffer = offer
                        advance(if (offer) GiveStep.PHOTO else GiveStep.TITLE)
                    },
                )
                GiveStep.PHOTO -> PhotoStep(
                    onPhotoTaken = { hasPhoto = true; advance(GiveStep.TITLE) },
                    onSkipPhoto = { advance(GiveStep.TITLE) },
                    onBack = { back(GiveStep.TYPE) },
                )
                GiveStep.TITLE -> TitleStep(
                    isOffer = isOffer,
                    title = title,
                    onTitleChange = { title = it },
                    description = description,
                    onDescriptionChange = { description = it },
                    onNext = { advance(GiveStep.LOCATION) },
                    onBack = { back(if (isOffer) GiveStep.PHOTO else GiveStep.TYPE) },
                )
                GiveStep.LOCATION -> LocationStep(
                    locationName = userLocationName.ifEmpty { userPostcode },
                    onPost = { advance(GiveStep.DONE) },
                    onBack = { back(GiveStep.TITLE) },
                    onChangeLocation = onLocationChange,
                )
                GiveStep.DONE -> SuccessStep(
                    isOffer = isOffer,
                    title = title,
                    onPostAnother = {
                        title = ""
                        description = ""
                        hasPhoto = false
                        goingForward = false
                        step = GiveStep.TYPE
                    },
                )
            }
        }

        // Progress dots at top (except on DONE screen)
        if (step != GiveStep.DONE) {
            Row(
                modifier = Modifier
                    .align(Alignment.TopCenter)
                    .padding(top = 16.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                val totalSteps = if (isOffer) 4 else 3
                val progress = when (step) {
                    GiveStep.TYPE -> 0
                    GiveStep.PHOTO -> 1
                    GiveStep.TITLE -> if (isOffer) 2 else 1
                    GiveStep.LOCATION -> if (isOffer) 3 else 2
                    GiveStep.DONE -> totalSteps
                }
                repeat(totalSteps) { i ->
                    val filled = i <= progress
                    Box(
                        modifier = Modifier
                            .size(width = if (i == progress) 24.dp else 8.dp, height = 8.dp)
                            .clip(CircleShape)
                            .background(
                                if (filled) MaterialTheme.colorScheme.primary
                                else MaterialTheme.colorScheme.surfaceVariant,
                            ),
                    )
                }
            }
        }
    }
}

@Composable
private fun TypeStep(
    isOffer: Boolean,
    onChoose: (Boolean) -> Unit,
) {
    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background),
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(horizontal = 28.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center,
        ) {
            // Friendly greeting
            Text(
                "Hi there!",
                style = MaterialTheme.typography.titleMedium,
                color = MaterialTheme.colorScheme.primary,
                fontWeight = FontWeight.Medium,
            )
            Spacer(Modifier.height(8.dp))
            Text(
                "What would you\nlike to do?",
                style = MaterialTheme.typography.displaySmall,
                fontWeight = FontWeight.Bold,
                textAlign = TextAlign.Center,
                lineHeight = 40.sp,
            )

            Spacer(Modifier.height(48.dp))

            // Give card - large, photo-forward
            Card(
                onClick = { onChoose(true) },
                modifier = Modifier
                    .fillMaxWidth()
                    .height(160.dp),
                shape = RoundedCornerShape(24.dp),
                colors = CardDefaults.cardColors(
                    containerColor = MaterialTheme.colorScheme.primaryContainer,
                ),
                elevation = CardDefaults.cardElevation(4.dp),
            ) {
                Box(modifier = Modifier.fillMaxSize()) {
                    // Large background icon
                    Icon(
                        Icons.Outlined.CardGiftcard,
                        contentDescription = null,
                        modifier = Modifier
                            .size(100.dp)
                            .align(Alignment.CenterEnd)
                            .padding(end = 16.dp),
                        tint = MaterialTheme.colorScheme.primary.copy(alpha = 0.15f),
                    )
                    Column(
                        modifier = Modifier
                            .align(Alignment.CenterStart)
                            .padding(start = 24.dp),
                    ) {
                        Icon(
                            Icons.Filled.CardGiftcard,
                            contentDescription = null,
                            modifier = Modifier.size(36.dp),
                            tint = MaterialTheme.colorScheme.primary,
                        )
                        Spacer(Modifier.height(8.dp))
                        Text(
                            "Give something",
                            style = MaterialTheme.typography.titleLarge,
                            fontWeight = FontWeight.Bold,
                            color = MaterialTheme.colorScheme.onPrimaryContainer,
                        )
                        Text(
                            "Give it to someone nearby",
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onPrimaryContainer.copy(alpha = 0.7f),
                        )
                    }
                }
            }

            Spacer(Modifier.height(16.dp))

            // Wanted card
            Card(
                onClick = { onChoose(false) },
                modifier = Modifier
                    .fillMaxWidth()
                    .height(160.dp),
                shape = RoundedCornerShape(24.dp),
                colors = CardDefaults.cardColors(
                    containerColor = MaterialTheme.colorScheme.tertiaryContainer,
                ),
                elevation = CardDefaults.cardElevation(4.dp),
            ) {
                Box(modifier = Modifier.fillMaxSize()) {
                    Icon(
                        Icons.Outlined.Search,
                        contentDescription = null,
                        modifier = Modifier
                            .size(100.dp)
                            .align(Alignment.CenterEnd)
                            .padding(end = 16.dp),
                        tint = MaterialTheme.colorScheme.tertiary.copy(alpha = 0.15f),
                    )
                    Column(
                        modifier = Modifier
                            .align(Alignment.CenterStart)
                            .padding(start = 24.dp),
                    ) {
                        Icon(
                            Icons.Filled.Search,
                            contentDescription = null,
                            modifier = Modifier.size(36.dp),
                            tint = MaterialTheme.colorScheme.tertiary,
                        )
                        Spacer(Modifier.height(8.dp))
                        Text(
                            "Ask for something",
                            style = MaterialTheme.typography.titleLarge,
                            fontWeight = FontWeight.Bold,
                            color = MaterialTheme.colorScheme.onTertiaryContainer,
                        )
                        Text(
                            "Post a free Wanted ad",
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onTertiaryContainer.copy(alpha = 0.7f),
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun PhotoStep(
    onPhotoTaken: () -> Unit,
    onSkipPhoto: () -> Unit,
    onBack: () -> Unit,
) {
    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Color(0xFF1C1B1A)),
    ) {
        // Camera viewfinder area
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .fillMaxHeight(0.72f),
            contentAlignment = Alignment.Center,
        ) {
            // Camera frame placeholder
            Box(
                modifier = Modifier
                    .fillMaxSize()
                    .background(Color(0xFF0A0A0A)),
                contentAlignment = Alignment.Center,
            ) {
                Column(
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    Icon(
                        Icons.Default.CameraAlt,
                        contentDescription = null,
                        modifier = Modifier.size(64.dp),
                        tint = Color.White.copy(alpha = 0.3f),
                    )
                    Spacer(Modifier.height(12.dp))
                    Text(
                        "Point your camera\nat the item",
                        style = MaterialTheme.typography.titleMedium,
                        color = Color.White.copy(alpha = 0.5f),
                        textAlign = TextAlign.Center,
                    )
                }

                // Corner frame markers
                val cornerColor = Color.White.copy(alpha = 0.6f)
                val cornerSize = 32.dp
                val stroke = 3.dp
                // TL
                Box(
                    modifier = Modifier
                        .align(Alignment.TopStart)
                        .padding(40.dp),
                ) {
                    Box(modifier = Modifier.size(cornerSize, stroke).background(cornerColor))
                    Box(modifier = Modifier.size(stroke, cornerSize).background(cornerColor))
                }
                // TR
                Box(
                    modifier = Modifier
                        .align(Alignment.TopEnd)
                        .padding(40.dp),
                ) {
                    Box(modifier = Modifier.size(cornerSize, stroke).align(Alignment.TopEnd).background(cornerColor))
                    Box(modifier = Modifier.size(stroke, cornerSize).align(Alignment.TopEnd).background(cornerColor))
                }
                // BL
                Box(
                    modifier = Modifier
                        .align(Alignment.BottomStart)
                        .padding(40.dp),
                ) {
                    Box(modifier = Modifier.size(cornerSize, stroke).align(Alignment.BottomStart).background(cornerColor))
                    Box(modifier = Modifier.size(stroke, cornerSize).align(Alignment.BottomStart).background(cornerColor))
                }
                // BR
                Box(
                    modifier = Modifier
                        .align(Alignment.BottomEnd)
                        .padding(40.dp),
                ) {
                    Box(modifier = Modifier.size(cornerSize, stroke).align(Alignment.BottomEnd).background(cornerColor))
                    Box(modifier = Modifier.size(stroke, cornerSize).align(Alignment.BottomEnd).background(cornerColor))
                }
            }
        }

        // Back button
        IconButton(
            onClick = onBack,
            modifier = Modifier.padding(8.dp),
        ) {
            Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back", tint = Color.White)
        }

        // Tip text + actions at bottom
        Column(
            modifier = Modifier
                .align(Alignment.BottomCenter)
                .padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(
                "A good photo helps items find a home faster",
                style = MaterialTheme.typography.bodyMedium,
                color = Color.White.copy(alpha = 0.7f),
            )
            Spacer(Modifier.height(20.dp))
            Row(
                horizontalArrangement = Arrangement.spacedBy(16.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                // Gallery button
                FilledTonalButton(
                    onClick = onPhotoTaken,
                    shape = RoundedCornerShape(16.dp),
                ) {
                    Icon(Icons.Default.Photo, contentDescription = null)
                    Spacer(Modifier.width(6.dp))
                    Text("Gallery")
                }

                // Take photo - main CTA
                FloatingActionButton(
                    onClick = onPhotoTaken,
                    shape = CircleShape,
                    containerColor = Color.White,
                    contentColor = Color.Black,
                    modifier = Modifier.size(72.dp),
                ) {
                    Icon(Icons.Default.CameraAlt, contentDescription = "Take photo", modifier = Modifier.size(32.dp))
                }

                // Skip
                TextButton(
                    onClick = onSkipPhoto,
                ) {
                    Text("Skip", color = Color.White.copy(alpha = 0.7f))
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun TitleStep(
    isOffer: Boolean,
    title: String,
    onTitleChange: (String) -> Unit,
    description: String,
    onDescriptionChange: (String) -> Unit,
    onNext: () -> Unit,
    onBack: () -> Unit,
) {
    val focusRequester = remember { FocusRequester() }
    LaunchedEffect(Unit) {
        delay(300)
        focusRequester.requestFocus()
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background)
            .padding(28.dp)
            .padding(top = 40.dp),
    ) {
        // Question
        Text(
            if (isOffer) "What are you\ngiving away?" else "What are you\nlooking for?",
            style = MaterialTheme.typography.displaySmall,
            fontWeight = FontWeight.Bold,
            lineHeight = 40.sp,
        )

        Spacer(Modifier.height(32.dp))

        // Main title field - large and prominent
        OutlinedTextField(
            value = title,
            onValueChange = onTitleChange,
            modifier = Modifier
                .fillMaxWidth()
                .focusRequester(focusRequester),
            placeholder = {
                Text(
                    if (isOffer) "e.g. Blue armchair, kids bike" else "e.g. Bed frame, sewing machine",
                    style = MaterialTheme.typography.titleMedium,
                    color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.35f),
                )
            },
            textStyle = MaterialTheme.typography.titleMedium,
            singleLine = true,
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Next),
            shape = RoundedCornerShape(16.dp),
        )

        Spacer(Modifier.height(16.dp))

        // Description field - smaller
        OutlinedTextField(
            value = description,
            onValueChange = onDescriptionChange,
            modifier = Modifier
                .fillMaxWidth()
                .height(100.dp),
            placeholder = {
                Text(
                    if (isOffer) "Condition, colour, size... (optional)" else "Any more details... (optional)",
                    color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.35f),
                )
            },
            maxLines = 3,
            shape = RoundedCornerShape(16.dp),
        )

        Spacer(Modifier.weight(1f))

        Row(
            horizontalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            OutlinedButton(
                onClick = onBack,
                modifier = Modifier.weight(1f),
                shape = RoundedCornerShape(16.dp),
            ) {
                Text("Back")
            }
            Button(
                onClick = onNext,
                modifier = Modifier.weight(2f),
                enabled = title.isNotBlank(),
                shape = RoundedCornerShape(16.dp),
            ) {
                Text(if (isOffer) "Almost done →" else "Next →")
            }
        }
    }
}

@Composable
private fun LocationStep(
    locationName: String = "",
    onPost: () -> Unit,
    onBack: () -> Unit,
    onChangeLocation: (() -> Unit)? = null,
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background)
            .padding(28.dp)
            .padding(top = 40.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(
            "Last step!",
            style = MaterialTheme.typography.titleMedium,
            color = MaterialTheme.colorScheme.primary,
            fontWeight = FontWeight.Medium,
        )
        Spacer(Modifier.height(8.dp))
        Text(
            "Where are\nyou based?",
            style = MaterialTheme.typography.displaySmall,
            fontWeight = FontWeight.Bold,
            lineHeight = 40.sp,
            textAlign = TextAlign.Center,
        )

        Spacer(Modifier.height(48.dp))

        // Location display card
        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(20.dp),
            colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer),
        ) {
            Row(
                modifier = Modifier.padding(20.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(
                    Icons.Default.LocationOn,
                    contentDescription = null,
                    tint = MaterialTheme.colorScheme.primary,
                    modifier = Modifier.size(32.dp),
                )
                Spacer(Modifier.width(12.dp))
                Column(modifier = Modifier.weight(1f)) {
                    Text(
                        locationName.ifEmpty { "Set your location" },
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.SemiBold,
                    )
                    Text(
                        if (locationName.isNotEmpty()) "From your saved postcode" else "Tap Change to set your area",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onPrimaryContainer.copy(alpha = 0.7f),
                    )
                }
                if (onChangeLocation != null) {
                    TextButton(onClick = onChangeLocation) { Text("Change") }
                }
            }
        }

        Spacer(Modifier.height(24.dp))

        Text(
            "Only your approximate area is shown — never your exact address.",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            textAlign = TextAlign.Center,
        )

        Spacer(Modifier.weight(1f))

        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            OutlinedButton(
                onClick = onBack,
                modifier = Modifier.weight(1f),
                shape = RoundedCornerShape(16.dp),
            ) {
                Text("Back")
            }
            Button(
                onClick = onPost,
                modifier = Modifier.weight(2f),
                shape = RoundedCornerShape(16.dp),
                colors = ButtonDefaults.buttonColors(
                    containerColor = MaterialTheme.colorScheme.primary,
                ),
            ) {
                Icon(Icons.AutoMirrored.Filled.Send, contentDescription = null, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text("Post it!", fontWeight = FontWeight.Bold)
            }
        }
    }
}

@Composable
private fun SuccessStep(
    isOffer: Boolean,
    title: String,
    onPostAnother: () -> Unit,
) {
    val checkScale = remember { Animatable(0f) }
    val ring1Scale = remember { Animatable(0.3f) }
    val ring1Alpha = remember { Animatable(0.8f) }
    val ring2Scale = remember { Animatable(0.3f) }
    val ring2Alpha = remember { Animatable(0.6f) }
    val textOffset = remember { Animatable(40f) }
    val textAlpha = remember { Animatable(0f) }
    val scope = rememberCoroutineScope()

    LaunchedEffect(Unit) {
        // Checkmark springs in
        checkScale.animateTo(1f, spring(dampingRatio = 0.45f, stiffness = 350f))
        // Expanding rings
        scope.launch {
            ring1Scale.animateTo(2.8f, tween(700, easing = FastOutSlowInEasing))
            ring1Alpha.animateTo(0f, tween(500, easing = FastOutSlowInEasing))
        }
        scope.launch {
            delay(150)
            ring2Scale.animateTo(2.8f, tween(700, easing = FastOutSlowInEasing))
            ring2Alpha.animateTo(0f, tween(500, easing = FastOutSlowInEasing))
        }
        // Text slides up
        scope.launch {
            delay(200)
            textOffset.animateTo(0f, spring(dampingRatio = 0.7f, stiffness = 300f))
            textAlpha.animateTo(1f, tween(300))
        }
    }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            modifier = Modifier.padding(40.dp),
        ) {
            // Celebration icon with rings
            Box(
                modifier = Modifier.size(140.dp),
                contentAlignment = Alignment.Center,
            ) {
                // Ring 2
                Box(
                    modifier = Modifier
                        .size(120.dp)
                        .graphicsLayer {
                            scaleX = ring2Scale.value
                            scaleY = ring2Scale.value
                            alpha = ring2Alpha.value
                        }
                        .clip(CircleShape)
                        .background(MaterialTheme.colorScheme.primary.copy(alpha = 0.12f)),
                )
                // Ring 1
                Box(
                    modifier = Modifier
                        .size(120.dp)
                        .graphicsLayer {
                            scaleX = ring1Scale.value
                            scaleY = ring1Scale.value
                            alpha = ring1Alpha.value
                        }
                        .clip(CircleShape)
                        .background(MaterialTheme.colorScheme.primary.copy(alpha = 0.18f)),
                )
                // Checkmark
                Icon(
                    Icons.Default.CheckCircle,
                    contentDescription = null,
                    modifier = Modifier
                        .size(90.dp)
                        .graphicsLayer { scaleX = checkScale.value; scaleY = checkScale.value },
                    tint = MaterialTheme.colorScheme.primary,
                )
            }

            Spacer(Modifier.height(28.dp))

            // Text slides up
            Column(
                horizontalAlignment = Alignment.CenterHorizontally,
                modifier = Modifier.graphicsLayer {
                    translationY = textOffset.value
                    alpha = textAlpha.value
                },
            ) {
                Text(
                    if (isOffer) "It's live!" else "Ad posted!",
                    style = MaterialTheme.typography.displaySmall,
                    fontWeight = FontWeight.Bold,
                )
                Spacer(Modifier.height(12.dp))
                if (title.isNotBlank()) {
                    Text(
                        "\"$title\"",
                        style = MaterialTheme.typography.titleMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        textAlign = TextAlign.Center,
                    )
                    Spacer(Modifier.height(8.dp))
                }

                // Community impact message
                Card(
                    modifier = Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(16.dp),
                    colors = CardDefaults.cardColors(
                        containerColor = MaterialTheme.colorScheme.primaryContainer,
                    ),
                ) {
                    Row(
                        modifier = Modifier.padding(16.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(
                            text = "🌱",
                            style = MaterialTheme.typography.titleLarge,
                        )
                        Spacer(Modifier.width(12.dp))
                        Text(
                            text = if (isOffer)
                                "Nearby Freeglers will see this straight away. Helping keep things out of landfill!"
                            else
                                "Freeglers with matching items will be notified. Good luck!",
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onPrimaryContainer,
                        )
                    }
                }

                Spacer(Modifier.height(32.dp))

                Button(
                    onClick = onPostAnother,
                    modifier = Modifier.fillMaxWidth(0.7f),
                    shape = RoundedCornerShape(16.dp),
                ) {
                    Text("Post another")
                }
            }
        }
    }
}
