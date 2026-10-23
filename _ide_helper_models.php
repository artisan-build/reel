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
 * @property \App\Enums\CaptureSeverity $severity
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RecordingMarker> $recordingMarkers
 * @property-read int|null $recording_markers_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RecordingSession> $recordingSessions
 * @property-read int|null $recording_sessions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ReplayView> $replayViews
 * @property-read int|null $replay_views_count
 * @method static \Database\Factories\ApplicationFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperApplication {}
}

namespace App\Models{
/**
 * @property-read \App\Models\RecordingSession|null $recordingSession
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperRecordingChunk {}
}

namespace App\Models{
/**
 * @property \App\Enums\RecordingEpochStatus $status
 * @property-read \App\Models\RecordingSession|null $recordingSession
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RecordingEpochTransition> $transitions
 * @property-read int|null $transitions_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpoch newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpoch newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpoch query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperRecordingEpoch {}
}

namespace App\Models{
/**
 * @property-read \App\Models\RecordingEpoch|null $recordingEpoch
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpochTransition newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpochTransition newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpochTransition query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperRecordingEpochTransition {}
}

namespace App\Models{
/**
 * @property-read \App\Models\Application|null $application
 * @property-read \App\Models\RecordingSession|null $recordingSession
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingMarker newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingMarker newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingMarker query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperRecordingMarker {}
}

namespace App\Models{
/**
 * @property-read \App\Models\RecordingSession|null $recordingSession
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingProtectionEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingProtectionEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingProtectionEvent query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperRecordingProtectionEvent {}
}

namespace App\Models{
/**
 * @property \App\Enums\RecordingSessionStatus $status
 * @property-read \App\Models\Application|null $application
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RecordingChunk> $chunks
 * @property-read int|null $chunks_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RecordingEpoch> $epochs
 * @property-read int|null $epochs_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RecordingMarker> $markers
 * @property-read int|null $markers_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RecordingProtectionEvent> $protectionEvents
 * @property-read int|null $protection_events_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ReplayView> $replayViews
 * @property-read int|null $replay_views_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RecordingSessionTransition> $transitions
 * @property-read int|null $transitions_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperRecordingSession {}
}

namespace App\Models{
/**
 * @property-read \App\Models\RecordingSession|null $recordingSession
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSessionTransition newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSessionTransition newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSessionTransition query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperRecordingSessionTransition {}
}

namespace App\Models{
/**
 * @property-read \App\Models\Application|null $application
 * @property-read \App\Models\RecordingSession|null $recordingSession
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReplayView newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReplayView newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReplayView query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperReplayView {}
}

namespace App\Models{
/**
 * @property-read \App\Models\Application|null $application
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserErasureAudit newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserErasureAudit newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserErasureAudit query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperUserErasureAudit {}
}

