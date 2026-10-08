-- "More Creators Like This" block on public profiles: on by default, the creator can turn it off in Settings > Creator Profile.
ALTER TABLE creator_profiles ADD COLUMN similar_off TINYINT(1) NOT NULL DEFAULT 0 AFTER directory_checked_at;
