package org.freegle.app.android.ui.navigation

import androidx.compose.animation.core.*
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Chat
import androidx.compose.material.icons.automirrored.outlined.Chat
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Explore
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.outlined.Explore
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.NavType
import androidx.navigation.compose.*
import androidx.navigation.navArgument
import kotlinx.coroutines.launch
import org.freegle.app.android.data.FreeglePreferences
import org.freegle.app.android.ui.components.FreegleHeartArrows
import org.freegle.app.android.ui.screens.*
import org.freegle.app.api.AuthManager
import org.freegle.app.api.FreegleApi
import org.freegle.app.repository.ChatRepository
import org.koin.compose.koinInject

sealed class Screen(val route: String) {
    data object Home : Screen("home")
    data object Explore : Screen("explore")
    data object Post : Screen("post_new")
    data object ChatList : Screen("chat_list")
    data object Profile : Screen("profile")
    data object PostDetail : Screen("post/{messageId}") {
        fun createRoute(messageId: Long) = "post/$messageId"
    }
    data object Chat : Screen("chat/{chatId}") {
        fun createRoute(chatId: Long) = "chat/$chatId"
    }
}

data class BottomNavItem(
    val screen: Screen,
    val label: String,
    val selectedIcon: ImageVector,
    val unselectedIcon: ImageVector,
)

// 5-tab layout with Post centred: Daily 5 | Explore | Post | Chat | Me
val bottomNavItems = listOf(
    BottomNavItem(Screen.Home, "Daily 5", Icons.Filled.Home, Icons.Outlined.Home),
    BottomNavItem(Screen.Explore, "Explore", Icons.Filled.Explore, Icons.Outlined.Explore),
    BottomNavItem(Screen.Post, "Post", Icons.Filled.Add, Icons.Filled.Add),
    BottomNavItem(Screen.ChatList, "Chat", Icons.AutoMirrored.Filled.Chat, Icons.AutoMirrored.Outlined.Chat),
    BottomNavItem(Screen.Profile, "Me", Icons.Filled.Person, Icons.Outlined.Person),
)

@Composable
fun FreegleNavHost(
    chatRepository: ChatRepository = koinInject(),
    prefs: FreeglePreferences = koinInject(),
    authManager: AuthManager = koinInject(),
    api: FreegleApi = koinInject(),
) {
    val navController = rememberNavController()
    val navBackStackEntry by navController.currentBackStackEntryAsState()
    val currentDestination = navBackStackEntry?.destination
    val scope = rememberCoroutineScope()

    val chatRooms by chatRepository.chatRooms.collectAsState()
    val totalUnread = chatRooms.sumOf { it.unseen }.coerceAtMost(99)

    // Onboarding state
    var showOnboarding by remember { mutableStateOf<Boolean?>(null) }
    var tourStep by remember { mutableIntStateOf(-1) } // -1 = no tour

    // Auto-login: restore or create credentials on startup
    LaunchedEffect(Unit) {
        // Check onboarding state
        showOnboarding = !prefs.isOnboardingComplete()

        // Try to restore saved credentials
        val savedJwt = prefs.getAuthJwt()
        val savedUserId = prefs.getAuthUserId()
        val savedPersistent = prefs.getAuthPersistent()

        if (savedJwt != null && savedUserId != null) {
            // Restore saved credentials
            authManager.setCredentials(savedJwt, savedUserId)
            if (savedPersistent != null) authManager.setPersistentToken(savedPersistent)
        } else {
            // First launch: create a device user account via V1 API
            try {
                val deviceId = prefs.getOrCreateDeviceId()
                val email = "app-$deviceId@users.ilovefreegle.org"
                val result = api.createDeviceUser(email)
                val jwt = result?.jwt
                val uid = result?.id
                if (result != null && result.ret == 0 && jwt != null && uid != null) {
                    authManager.setCredentials(jwt, uid)
                    result.persistent?.let { authManager.setPersistentToken(it) }
                    prefs.saveAuth(jwt, uid, result.persistent)
                }
            } catch (_: Exception) {
                // Auto-login failed - app still works for browsing without auth
            }
        }
    }

    val showBottomBar = bottomNavItems.any { item ->
        currentDestination?.hierarchy?.any { it.route == item.screen.route } == true
    }

    // Pulsing animation for the Post button - pulses a few times then stops
    var pulseActive by remember { mutableStateOf(true) }
    LaunchedEffect(Unit) {
        kotlinx.coroutines.delay(4500) // Pulse for ~5 cycles then stop
        pulseActive = false
    }
    val pulseScale by animateFloatAsState(
        targetValue = if (pulseActive) 1.12f else 1f,
        animationSpec = if (pulseActive) repeatable(
            iterations = 5,
            animation = tween(900, easing = FastOutSlowInEasing),
            repeatMode = RepeatMode.Reverse,
        ) else spring(),
        label = "pulse_scale",
        finishedListener = { pulseActive = false },
    )

    // Show onboarding on first launch
    if (showOnboarding == null) {
        // Still loading preference
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            CircularProgressIndicator()
        }
        return
    }
    if (showOnboarding == true) {
        OnboardingScreen(
            onComplete = {
                scope.launch { prefs.setOnboardingComplete() }
                showOnboarding = false
                tourStep = 0 // Start feature tour after onboarding
            },
        )
        return
    }

    Scaffold(
        bottomBar = {
            if (showBottomBar) {
                NavigationBar(
                    containerColor = MaterialTheme.colorScheme.surface,
                    tonalElevation = 8.dp,
                ) {
                    bottomNavItems.forEach { item ->
                        val selected = currentDestination?.hierarchy?.any {
                            it.route == item.screen.route
                        } == true

                        if (item.screen == Screen.Post) {
                            // Special Post button
                            NavigationBarItem(
                                selected = selected,
                                onClick = {
                                    navController.navigate(item.screen.route) {
                                        popUpTo(navController.graph.findStartDestination().id) {
                                            saveState = true
                                        }
                                        launchSingleTop = true
                                        restoreState = true
                                    }
                                },
                                icon = {
                                    Box(
                                        modifier = Modifier
                                            .size(52.dp)
                                            .scale(if (!selected) pulseScale else 1f)
                                            .background(
                                                color = MaterialTheme.colorScheme.primary,
                                                shape = CircleShape,
                                            ),
                                        contentAlignment = Alignment.Center,
                                    ) {
                                        // Heart-shaped recycling arrows from Freegle logo
                                        FreegleHeartArrows(
                                            modifier = Modifier.size(34.dp),
                                            color = MaterialTheme.colorScheme.onPrimary,
                                        )
                                    }
                                },
                                label = {
                                    Text(
                                        "Post",
                                        fontWeight = FontWeight.SemiBold,
                                        color = if (selected) MaterialTheme.colorScheme.primary
                                        else MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                },
                                colors = NavigationBarItemDefaults.colors(
                                    indicatorColor = Color.Transparent,
                                ),
                            )
                        } else {
                            NavigationBarItem(
                                selected = selected,
                                onClick = {
                                    navController.navigate(item.screen.route) {
                                        popUpTo(navController.graph.findStartDestination().id) {
                                            saveState = true
                                        }
                                        launchSingleTop = true
                                        restoreState = true
                                    }
                                },
                                icon = {
                                    if (item.screen == Screen.ChatList && totalUnread > 0) {
                                        BadgedBox(
                                            badge = {
                                                Badge { Text(totalUnread.toString()) }
                                            },
                                        ) {
                                            Icon(
                                                imageVector = if (selected) item.selectedIcon else item.unselectedIcon,
                                                contentDescription = item.label,
                                            )
                                        }
                                    } else {
                                        Icon(
                                            imageVector = if (selected) item.selectedIcon else item.unselectedIcon,
                                            contentDescription = item.label,
                                        )
                                    }
                                },
                                label = { Text(item.label) },
                            )
                        }
                    }
                }
            }
        },
    ) { innerPadding ->
        Box(modifier = Modifier.fillMaxSize().padding(innerPadding)) {
        NavHost(
            navController = navController,
            startDestination = Screen.Home.route,
        ) {
            composable(Screen.Home.route) {
                HomeScreen(
                    onMessageClick = { messageId ->
                        navController.navigate(Screen.PostDetail.createRoute(messageId))
                    },
                    onPostWantedClick = {
                        navController.navigate(Screen.Post.route) {
                            popUpTo(navController.graph.findStartDestination().id) {
                                saveState = true
                            }
                            launchSingleTop = true
                            restoreState = true
                        }
                    },
                    onNavigateToExplore = {
                        navController.navigate(Screen.Explore.route) {
                            popUpTo(navController.graph.findStartDestination().id) {
                                saveState = true
                            }
                            launchSingleTop = true
                            restoreState = true
                        }
                    },
                )
            }
            composable(Screen.Explore.route) {
                ExploreScreen(
                    onMessageClick = { messageId ->
                        navController.navigate(Screen.PostDetail.createRoute(messageId))
                    },
                    onPostWantedClick = {
                        navController.navigate(Screen.Post.route) {
                            popUpTo(navController.graph.findStartDestination().id) {
                                saveState = true
                            }
                            launchSingleTop = true
                            restoreState = true
                        }
                    },
                )
            }
            composable(Screen.Post.route) {
                var postcode by remember { mutableStateOf("") }
                var locationName by remember { mutableStateOf("") }
                LaunchedEffect(Unit) {
                    postcode = prefs.getPostcode()
                    locationName = prefs.getLocationName()
                }
                GiveScreen(
                    userPostcode = postcode,
                    userLocationName = locationName,
                )
            }
            composable(Screen.ChatList.route) {
                ChatListScreen(
                    onChatClick = { chatId ->
                        navController.navigate(Screen.Chat.createRoute(chatId))
                    },
                    onBrowseClick = {
                        navController.navigate(Screen.Home.route) {
                            popUpTo(navController.graph.findStartDestination().id) {
                                saveState = true
                            }
                            launchSingleTop = true
                            restoreState = true
                        }
                    },
                )
            }
            composable(Screen.Profile.route) {
                ProfileScreen()
            }
            composable(
                route = Screen.PostDetail.route,
                arguments = listOf(navArgument("messageId") { type = NavType.LongType }),
            ) { backStackEntry ->
                val messageId = backStackEntry.arguments?.getLong("messageId") ?: return@composable
                PostDetailScreen(
                    messageId = messageId,
                    onBack = { navController.popBackStack() },
                    onChatClick = { chatId ->
                        navController.navigate(Screen.Chat.createRoute(chatId))
                    },
                    onMessageClick = { otherId ->
                        navController.navigate(Screen.PostDetail.createRoute(otherId))
                    },
                )
            }
            composable(
                route = Screen.Chat.route,
                arguments = listOf(navArgument("chatId") { type = NavType.LongType }),
            ) { backStackEntry ->
                val chatId = backStackEntry.arguments?.getLong("chatId") ?: return@composable
                ChatScreen(
                    chatId = chatId,
                    onBack = { navController.popBackStack() },
                )
            }
        }

        // Feature tour overlay (z-ordered above NavHost inside the Box)
        if (tourStep >= 0) {
            FeatureTourOverlay(
                currentStep = tourStep,
                onNext = {
                    tourStep++
                    if (tourStep >= 5) {
                        tourStep = -1
                        scope.launch { prefs.setTourComplete() }
                    }
                },
                onSkip = {
                    tourStep = -1
                    scope.launch { prefs.setTourComplete() }
                },
            )
        }
        } // end Box
    }
}
