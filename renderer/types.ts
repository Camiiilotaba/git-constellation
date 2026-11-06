export interface ContributionDay { date: string; count: number }
export interface ConstellationData {
  schemaVersion: 1;
  mode: 'demo';
  profile: { username: string; name: string; role: string; stack: string };
  theme: { cyan: string; green: string };
  period: { start: string; end: string };
  days: ContributionDay[];
  metrics: {
    totalContributions: number;
    activeDays: number;
    longestStreak: number;
    pullRequests: number | null;
    codeReviews: number | null;
  };
  languages: { name: string; repositories: number; color: string }[];
  privateContributionsIncluded: boolean;
}

export interface WeeklyPoint {
  start: string;
  end: string;
  count: number;
  midpoint: number;
  x: number;
  y: number;
  intensity: number;
  radius: number;
}

export interface ChartGeometry {
  left: number;
  right: number;
  top: number;
  bottom: number;
}
