# R8 rules for the Freegle and ModTools apps (release builds only).
#
# Both apps are built from THIS native project: the ModTools job rewrites
# org.ilovefreegle.direct -> org.ilovefreegle.modtools in build.gradle, the manifest and
# MainActivity.java, but it does NOT touch this file. Every rule below therefore matches on
# an annotation, a superclass or an interface, never on our own package name, so a rule
# cannot silently stop applying to ModTools.
#
# Capacitor's own rules (node_modules/@capacitor/android/capacitor/proguard-rules.pro,
# applied via consumerProguardFiles) already keep @CapacitorPlugin classes, Plugin
# subclasses and Cordova plugins. Firebase, Facebook, Stripe and play-services likewise
# ship their own. What follows is only what nothing else covers.

# The WebView bridge. MainActivity installs ShareIntentBridge as window.FreegleShare, so its
# methods are reachable from the Nuxt bundle and from nowhere in the Java call graph - R8
# would otherwise strip or rename them and sharing an image into the app would silently stop
# starting an OFFER. proguard-android-optimize.txt carries this rule too; it is repeated here
# because losing it is invisible until a user tries to share.
-keepclassmembers class * {
    @android.webkit.JavascriptInterface <methods>;
}

# @capgo/capacitor-social-login calls back into MainActivity through this interface to hand
# over Google credential results, so the implementing class keeps its methods.
-keep class * implements ee.forgr.capacitor.social.login.ModifiedMainActivityForSocialLoginPlugin { *; }

# The push plugin resolves the launcher activity with
# Class.forName(getPackageName() + ".MainActivity") for background notifications, and catches
# the failure - a stripped or renamed MainActivity would mean silently no background pushes.
# AGP generates a keep rule for manifest-declared components, so this is belt and braces.
-keep class * extends com.getcapacitor.BridgeActivity { *; }

# Keep enough to deobfuscate native crashes. The mapping file travels inside the AAB, so Play
# symbolicates uploads automatically; Sentry only ever sees WebView JS stacks.
-keepattributes SourceFile,LineNumberTable
-renamesourcefileattribute SourceFile
