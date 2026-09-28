import { useAuth } from '../context/AuthContext';

/** const { canWrite, isOwner, isSuperAdmin, level } = usePermissions(); */
export function usePermissions() {
  const { permissions } = useAuth();
  return permissions;
}
