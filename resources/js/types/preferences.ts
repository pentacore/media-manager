import type { SharedUserResource } from '@/typefinder/resources/SharedUserResource';

export type UserPreferences = SharedUserResource['preferences'];

export type TimeFormat = UserPreferences['time_format'];

export type DateFormat = UserPreferences['date_format'];
