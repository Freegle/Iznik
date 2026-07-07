#!/usr/bin/env node
/**
 * Migrate existing Yesterday users to new schema with passwords
 */

const fs = require('fs').promises;
const bcrypt = require('bcrypt');
const readline = require('readline');

const USERS_FILE = '/var/www/FreegleDocker/yesterday/data/2fa/2fa-users.json';

const rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout
});

function question(prompt) {
    return new Promise((resolve) => {
        rl.question(prompt, resolve);
    });
}

async function main() {
    console.log('Yesterday User Migration Script');
    console.log('================================\n');

    // Load existing users
    const data = await fs.readFile(USERS_FILE, 'utf8');
    const users = JSON.parse(data);

    console.log(`Found ${Object.keys(users).length} users to migrate:\n`);

    const updates = [];

    for (const [username, user] of Object.entries(users)) {
        console.log(`User: ${username}`);
        console.log(`  Created: ${user.created || 'Unknown'}`);
        console.log(`  Has password: ${user.password ? 'Yes' : 'No'}`);
        console.log(`  Permission: ${user.permission || 'Not set'}`);

        // Check if migration needed
        if (!user.password) {
            console.log(`  → Needs migration\n`);

            const password = await question(`Enter password for ${username}: `);
            const permission = await question(`Enter permission (Admin/Support) [Admin]: `) || 'Admin';

            const hashedPassword = await bcrypt.hash(password, 10);

            users[username] = {
                ...user,
                password: hashedPassword,
                permission: permission,
                failedAttempts: 0,
                lockedUntil: null,
                lastLogin: null
            };

            updates.push(username);
            console.log(`  ✅ Updated ${username}\n`);
        } else {
            console.log(`  ✓ Already migrated\n`);
        }
    }

    if (updates.length > 0) {
        // Save updated users
        await fs.writeFile(USERS_FILE, JSON.stringify(users, null, 2));
        console.log(`\n✅ Migration complete! Updated ${updates.length} user(s): ${updates.join(', ')}`);
        console.log('\nPlease restart the 2FA gateway container to apply changes:');
        console.log('  docker compose -f yesterday/docker-compose.yesterday-services.yml restart yesterday-2fa');
    } else {
        console.log('\n✓ No migration needed. All users already have passwords.');
    }

    rl.close();
}

main().catch(err => {
    console.error('Error:', err);
    process.exit(1);
});
