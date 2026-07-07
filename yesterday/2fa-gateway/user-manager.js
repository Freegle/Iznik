#!/usr/bin/env node
/**
 * Yesterday User Management CLI
 *
 * Manages users for the Yesterday backup system.
 * This tool must be run on the server with access to the users file.
 *
 * Usage:
 *   ./user-manager.js list                              - List all users
 *   ./user-manager.js add <username> <password>         - Add user (Support permission)
 *   ./user-manager.js add <username> <password> Admin   - Add admin user
 *   ./user-manager.js reset <username> <new-password>   - Reset user password
 *   ./user-manager.js permission <username> <Admin|Support> - Change user permission
 *   ./user-manager.js delete <username>                 - Delete user
 *   ./user-manager.js unlock <username>                 - Unlock locked account
 */

const fs = require('fs').promises;
const bcrypt = require('bcrypt');
const speakeasy = require('speakeasy');
const qrcode = require('qrcode');
const path = require('path');

const USERS_FILE = process.env.USERS_FILE || '/var/www/FreegleDocker/yesterday/data/2fa/2fa-users.json';

// Load users
async function loadUsers() {
    try {
        const data = await fs.readFile(USERS_FILE, 'utf8');
        return JSON.parse(data);
    } catch (err) {
        if (err.code === 'ENOENT') {
            return {};
        }
        throw err;
    }
}

// Save users
async function saveUsers(users) {
    await fs.mkdir(path.dirname(USERS_FILE), { recursive: true });
    await fs.writeFile(USERS_FILE, JSON.stringify(users, null, 2));
}

// List users
async function listUsers() {
    const users = await loadUsers();

    if (Object.keys(users).length === 0) {
        console.log('No users found.');
        return;
    }

    console.log('\nUsers:');
    console.log('─'.repeat(80));
    console.log('Username'.padEnd(20), 'Permission'.padEnd(15), 'Created'.padEnd(25), 'Last Login');
    console.log('─'.repeat(80));

    for (const [username, user] of Object.entries(users)) {
        const permission = user.permission || 'Support';
        const created = user.created ? new Date(user.created).toLocaleString() : 'Unknown';
        const lastLogin = user.lastLogin ? new Date(user.lastLogin).toLocaleString() : 'Never';
        const locked = user.lockedUntil && user.lockedUntil > Date.now() ? ' [LOCKED]' : '';

        console.log(
            username.padEnd(20),
            (permission + locked).padEnd(15),
            created.padEnd(25),
            lastLogin
        );
    }
    console.log('─'.repeat(80));
    console.log(`Total users: ${Object.keys(users).length}\n`);
}

// Add user
async function addUser(username, password, permission = 'Support') {
    const users = await loadUsers();

    if (users[username]) {
        console.error(`Error: User '${username}' already exists.`);
        process.exit(1);
    }

    const validPermissions = ['Support', 'Admin'];
    if (!validPermissions.includes(permission)) {
        console.error(`Error: Invalid permission '${permission}'. Must be 'Support' or 'Admin'.`);
        process.exit(1);
    }

    // Hash password
    const hashedPassword = await bcrypt.hash(password, 10);

    // Generate TOTP secret
    const secret = speakeasy.generateSecret({
        name: `Yesterday (${username})`,
        issuer: 'Freegle Yesterday'
    });

    users[username] = {
        secret: secret.base32,
        password: hashedPassword,
        permission: permission,
        created: new Date().toISOString(),
        failedAttempts: 0,
        lockedUntil: null
    };

    await saveUsers(users);

    console.log(`\n✅ User '${username}' created successfully!`);
    console.log(`   Permission: ${permission}`);
    console.log('\n📱 2FA Setup:');
    console.log(`   Secret: ${secret.base32}`);
    console.log(`   QR Code URL: ${secret.otpauth_url}`);
    console.log('\n   Scan this QR code with Google Authenticator:');

    // Generate QR code
    try {
        const qr = await qrcode.toString(secret.otpauth_url, { type: 'terminal', small: true });
        console.log(qr);
    } catch (err) {
        console.log('   (Unable to generate QR code in terminal)');
    }

    console.log('\n   Or manually enter this key in your authenticator app:');
    console.log(`   ${secret.base32}\n`);
}

// Reset password
async function resetPassword(username, newPassword) {
    const users = await loadUsers();

    if (!users[username]) {
        console.error(`Error: User '${username}' not found.`);
        process.exit(1);
    }

    // Hash new password
    const hashedPassword = await bcrypt.hash(newPassword, 10);
    users[username].password = hashedPassword;

    // Reset failed attempts and unlock account
    users[username].failedAttempts = 0;
    users[username].lockedUntil = null;

    await saveUsers(users);

    console.log(`\n✅ Password reset successfully for user '${username}'.`);
    console.log(`   Account has been unlocked if it was locked.\n`);
}

// Change permission
async function changePermission(username, newPermission) {
    const users = await loadUsers();

    if (!users[username]) {
        console.error(`Error: User '${username}' not found.`);
        process.exit(1);
    }

    const validPermissions = ['Support', 'Admin'];
    if (!validPermissions.includes(newPermission)) {
        console.error(`Error: Invalid permission '${newPermission}'. Must be 'Support' or 'Admin'.`);
        process.exit(1);
    }

    const oldPermission = users[username].permission || 'Support';
    users[username].permission = newPermission;

    await saveUsers(users);

    console.log(`\n✅ Permission changed for user '${username}'.`);
    console.log(`   ${oldPermission} → ${newPermission}\n`);
}

// Delete user
async function deleteUser(username) {
    const users = await loadUsers();

    if (!users[username]) {
        console.error(`Error: User '${username}' not found.`);
        process.exit(1);
    }

    delete users[username];
    await saveUsers(users);

    console.log(`\n✅ User '${username}' deleted successfully.\n`);
}

// Unlock account
async function unlockAccount(username) {
    const users = await loadUsers();

    if (!users[username]) {
        console.error(`Error: User '${username}' not found.`);
        process.exit(1);
    }

    users[username].failedAttempts = 0;
    users[username].lockedUntil = null;

    await saveUsers(users);

    console.log(`\n✅ Account '${username}' unlocked successfully.\n`);
}

// Show help
function showHelp() {
    console.log(`
Yesterday User Management CLI

Usage:
  user-manager.js list                              - List all users
  user-manager.js add <username> <password>         - Add user (Support permission)
  user-manager.js add <username> <password> Admin   - Add admin user
  user-manager.js reset <username> <new-password>   - Reset user password
  user-manager.js permission <username> <permission> - Change user permission (Admin/Support)
  user-manager.js delete <username>                 - Delete user
  user-manager.js unlock <username>                 - Unlock locked account

Examples:
  user-manager.js list
  user-manager.js add alice mypassword123
  user-manager.js add bob secretpass Admin
  user-manager.js reset alice newpassword456
  user-manager.js permission alice Admin
  user-manager.js delete bob
  user-manager.js unlock alice

Notes:
  - Passwords must be provided via command line (use secure shell connection)
  - 2FA secrets are generated automatically when creating users
  - All changes take effect immediately
  - Users must set up their authenticator app when first created
`);
}

// Main
async function main() {
    const args = process.argv.slice(2);
    const command = args[0];

    try {
        switch (command) {
            case 'list':
                await listUsers();
                break;

            case 'add':
                if (args.length < 3) {
                    console.error('Error: Missing arguments. Usage: add <username> <password> [Admin]');
                    process.exit(1);
                }
                await addUser(args[1], args[2], args[3] || 'Support');
                break;

            case 'reset':
                if (args.length < 3) {
                    console.error('Error: Missing arguments. Usage: reset <username> <new-password>');
                    process.exit(1);
                }
                await resetPassword(args[1], args[2]);
                break;

            case 'permission':
                if (args.length < 3) {
                    console.error('Error: Missing arguments. Usage: permission <username> <Admin|Support>');
                    process.exit(1);
                }
                await changePermission(args[1], args[2]);
                break;

            case 'delete':
                if (args.length < 2) {
                    console.error('Error: Missing arguments. Usage: delete <username>');
                    process.exit(1);
                }
                await deleteUser(args[1]);
                break;

            case 'unlock':
                if (args.length < 2) {
                    console.error('Error: Missing arguments. Usage: unlock <username>');
                    process.exit(1);
                }
                await unlockAccount(args[1]);
                break;

            case 'help':
            case '--help':
            case '-h':
                showHelp();
                break;

            default:
                console.error(`Error: Unknown command '${command}'.`);
                showHelp();
                process.exit(1);
        }
    } catch (err) {
        console.error(`\nError: ${err.message}`);
        process.exit(1);
    }
}

main();
