export interface ContributionDay { date: string; count: number }
export interface ConstellationSource {
  provider: 'github';
  login: string;
  restrictedContributions: number;
  ownAccount: boolean;
  visibleTotals: { commits: number; issues: number; pullRequests: number; reviews: number; repositories: number };
  repositoriesScanned: number;
}

export interface ConstellationData {
  schemaVersion: 2;
  mode: 'demo' | 'live';
  generatedAt: string;
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
  privateContributions: 'included' | 'excluded' | 'unknown';
  source: ConstellationSource | null;
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
