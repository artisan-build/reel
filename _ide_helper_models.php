<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property array<array-key, mixed> $allowed_origins
 * @property \App\Enums\CaptureSeverity $severity
 * @property array<array-key, mixed> $mask_selectors
 * @property array<array-key, mixed> $block_selectors
 * @property array<array-key, mixed> $excluded_paths
 * @property int $sampling_percent
 * @property bool $ingest_enabled
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ApplicationCredential> $credentials
 * @property-read int|null $credentials_count
 * @method static \Database\Factories\ApplicationFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereAllowedOrigins($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereBlockSelectors($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereExcludedPaths($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereIngestEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereMaskSelectors($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereSamplingPercent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereSeverity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperApplication {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $application_id
 * @property string|null $public_key
 * @property string $algorithm
 * @property \App\Enums\CredentialStatus|null $status
 * @property string|null $enrollment_code_hash
 * @property \Carbon\CarbonImmutable|null $enrollment_expires_at
 * @property \Carbon\CarbonImmutable|null $enrolled_at
 * @property \Carbon\CarbonImmutable|null $revoked_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \App\Models\Application $application
 * @method static \Database\Factories\ApplicationCredentialFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereAlgorithm($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereApplicationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereEnrolledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereEnrollmentCodeHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereEnrollmentExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential wherePublicKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereRevokedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperApplicationCredential {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Carbon\CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property string|null $two_factor_confirmed_at
 * @property bool $is_admin
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Passkeys\Passkey> $passkeys
 * @property-read int|null $passkeys_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereIsAdmin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorConfirmedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorRecoveryCodes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperUser {}
}

