#!/usr/bin/env ruby
# frozen_string_literal: true

# Adds the Notification Service Extension (NSE) target to ios/App/App.xcodeproj so it
# builds + signs on CircleCI like any other target — no manual Xcode step required.
#
# Idempotent: safe to run on every CI build (and after `npx cap sync ios`, which can
# regenerate the iOS project). If the target already exists it only refreshes the
# source/Info.plist references and exits.
#
# Source of truth for the NSE code is the plugin package; this script copies it from
# node_modules into ios/App/NotificationServiceExtension/ before wiring the target, so
# there is a single canonical copy (the gitignored ios/App copy is derived, like cap sync).
#
# CWD-independent: all paths derive from this file's location (fastlane/ → project root).
#
# Requires the `xcodeproj` gem, which ships as a fastlane dependency (run under
# `bundle exec`).

require 'fileutils'
require 'xcodeproj'

EXT_NAME      = 'NotificationServiceExtension'
APP_TARGET    = 'Freegle'
APP_BUNDLE_ID = 'org.ilovefreegle.iphone'
NSE_BUNDLE_ID = "#{APP_BUNDLE_ID}.#{EXT_NAME}"
DEPLOY_TARGET = '15.0'

root      = File.expand_path('..', __dir__)              # fastlane/ -> project root
proj_path = File.join(root, 'ios/App/App.xcodeproj')
# NSE files live next to App.xcodeproj, i.e. ios/App/NotificationServiceExtension/.
nse_dir   = File.join(root, 'ios/App', EXT_NAME)
plugin_nse = File.join(root, 'node_modules/@freegle/capacitor-push-notifications-cap8/ios/NotificationServiceExtension')

unless File.directory?(File.dirname(proj_path))
  warn "add_nse_target: #{proj_path} not found — has `npx cap add ios` / `cap sync` run? Skipping."
  exit 0
end

# 1. Bring the NSE source in from the plugin package (canonical copy).
if File.exist?(File.join(plugin_nse, 'NotificationService.swift'))
  FileUtils.mkdir_p(nse_dir)
  FileUtils.cp(File.join(plugin_nse, 'NotificationService.swift'), nse_dir)
  FileUtils.cp(File.join(plugin_nse, 'Info.plist'), nse_dir)
  puts "add_nse_target: copied NSE source from plugin package"
elsif File.exist?(File.join(nse_dir, 'NotificationService.swift'))
  puts "add_nse_target: using existing NSE source in #{nse_dir}"
else
  warn "add_nse_target: NSE source not found in plugin (#{plugin_nse}) or #{nse_dir} — skipping (notifications will fall back to plain text on iOS)."
  exit 0
end

project   = Xcodeproj::Project.open(proj_path)
app_target = project.targets.find { |t| t.name == APP_TARGET }
unless app_target
  warn "add_nse_target: main target '#{APP_TARGET}' not found — skipping."
  exit 0
end

# Common build settings applied to every configuration of the NSE target.
def apply_ext_settings(config)
  s = config.build_settings
  s['PRODUCT_BUNDLE_IDENTIFIER']    = NSE_BUNDLE_ID
  s['PRODUCT_NAME']                 = '$(TARGET_NAME)'
  s['INFOPLIST_FILE']               = "#{EXT_NAME}/Info.plist"
  s['SWIFT_VERSION']                = '5.0'
  s['IPHONEOS_DEPLOYMENT_TARGET']   = DEPLOY_TARGET
  s['TARGETED_DEVICE_FAMILY']       = '1,2'
  s['CODE_SIGN_STYLE']              = 'Manual'
  s['SKIP_INSTALL']                 = 'NO'
  # Version build settings so the Info.plist $(MARKETING_VERSION)/$(CURRENT_PROJECT_VERSION)
  # resolve; fastlane's increment_version_number / increment_build_number overwrite these
  # for ALL targets on each build, keeping the NSE in lockstep with the app.
  s['MARKETING_VERSION']          ||= '1.0'
  s['CURRENT_PROJECT_VERSION']    ||= '1'
end

existing = project.targets.find { |t| t.name == EXT_NAME }

if existing
  puts "add_nse_target: target already exists — refreshing settings + file refs"
  ext_target = existing
  existing.build_configurations.each { |c| apply_ext_settings(c) }
else
  puts "add_nse_target: creating '#{EXT_NAME}' app-extension target"
  ext_target = project.new_target(:app_extension, EXT_NAME, :ios, DEPLOY_TARGET)
  ext_target.build_configurations.each { |c| apply_ext_settings(c) }

  # Build the app target before the extension, and embed the .appex inside the app.
  app_target.add_dependency(ext_target)

  embed = app_target.copy_files_build_phases.find { |p| p.symbol_dst_subfolder_spec == :plug_ins }
  if embed.nil?
    embed = app_target.new_copy_files_build_phase('Embed App Extensions')
    embed.symbol_dst_subfolder_spec = :plug_ins
  end
  build_file = embed.add_file_reference(ext_target.product_reference)
  build_file.settings = { 'ATTRIBUTES' => ['RemoveHeadersOnCopy'] }
end

# Ensure the source file is in a group and in the NSE target's compile phase (idempotent).
# The group path is relative to SOURCE_ROOT (the .xcodeproj's dir = ios/App), and the
# NSE source lives at ios/App/NotificationServiceExtension/ (nse_dir above). The path must
# therefore be EXT_NAME alone — NOT "App/#{EXT_NAME}", which resolved to
# ios/App/App/NotificationServiceExtension/ and made the archive fail with
# "Build input file cannot be found: .../App/NotificationServiceExtension/NotificationService.swift".
group = project.main_group.find_subpath(EXT_NAME, true)
group.set_source_tree('SOURCE_ROOT')
group.set_path(EXT_NAME)

swift_name = 'NotificationService.swift'
swift_ref  = group.files.find { |f| f.display_name == swift_name } ||
             group.new_reference(swift_name)
unless ext_target.source_build_phase.files_references.include?(swift_ref)
  ext_target.add_file_references([swift_ref])
end

# Make sure Info.plist is tracked in the group (not compiled).
unless group.files.any? { |f| f.display_name == 'Info.plist' }
  group.new_reference('Info.plist')
end

project.save
puts "add_nse_target: done (#{NSE_BUNDLE_ID})"
