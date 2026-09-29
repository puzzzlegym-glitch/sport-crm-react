import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import { ToastProvider } from './components/ui/ToastProvider';
import ProtectedRoute from './components/layout/ProtectedRoute';
import LoginPage from './pages/LoginPage';
import RegisterPage from './pages/RegisterPage';
import ForgotPasswordPage from './pages/ForgotPasswordPage';
import ResetPasswordPage from './pages/ResetPasswordPage';
import DashboardPage from './pages/DashboardPage';
import PaymentsPage from './pages/PaymentsPage';
import TariffsPage from './pages/TariffsPage';
import SalesPage from './pages/SalesPage';
import ClientsPage from './pages/ClientsPage';
import ClientCardPage from './pages/ClientCardPage';
import InvoicesPage from './pages/InvoicesPage';
import InvoiceCardPage from './pages/InvoiceCardPage';
import InvoiceGroupCardPage from './pages/InvoiceGroupCardPage';
import SaasPage from './pages/SaasPage';
import SaasPaymentsPage from './pages/SaasPaymentsPage';
import UsersPage from './pages/UsersPage';
import BillingPage from './pages/BillingPage';
import ProductsPage from './pages/ProductsPage';
import ClubsPage from './pages/ClubsPage';
import SettingsPage from './pages/SettingsPage';
import VisitsPage from './pages/VisitsPage';
import ArrivalsPage from './pages/ArrivalsPage';
import WarehousePage from './pages/WarehousePage';
import CashPage from './pages/CashPage';
import TrainersPage from './pages/TrainersPage';
import GroupSessionsPage from './pages/GroupSessionsPage';
import AccessPage from './pages/AccessPage';
import FinancePage from './pages/FinancePage';
import CertificatesPage from './pages/CertificatesPage';
import PrroSettingsPage from './pages/PrroSettingsPage';
import HelpPage from './pages/HelpPage';
import ClientServicePage from './pages/ClientServicePage';
import EquipmentPage from './pages/EquipmentPage';

export default function App() {
  return (
    <AuthProvider>
      <ToastProvider>
        <BrowserRouter>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route path="/register" element={<RegisterPage />} />
            <Route path="/forgot-password" element={<ForgotPasswordPage />} />
            <Route path="/reset-password" element={<ResetPasswordPage />} />

            <Route
              path="/dashboard"
              element={
                <ProtectedRoute permission="dashboard.view">
                  <DashboardPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/payments"
              element={
                <ProtectedRoute permission="payments.view">
                  <PaymentsPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/tariffs"
              element={
                <ProtectedRoute permission="tariffs.view">
                  <TariffsPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/sales"
              element={
                <ProtectedRoute permission="sales.view">
                  <SalesPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/clients"
              element={
                <ProtectedRoute permission="clients.view">
                  <ClientsPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/clients/:id"
              element={
                <ProtectedRoute permission="clients.view">
                  <ClientCardPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/invoices"
              element={
                <ProtectedRoute permission="invoices.view">
                  <InvoicesPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/invoices/:id"
              element={
                <ProtectedRoute permission="invoices.view">
                  <InvoiceCardPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/invoices/groups/:id"
              element={
                <ProtectedRoute permission="invoices.view">
                  <InvoiceGroupCardPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/saas"
              element={
                <ProtectedRoute requireSuperAdmin>
                  <SaasPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/saas-payments"
              element={
                <ProtectedRoute requireSuperAdmin>
                  <SaasPaymentsPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/users"
              element={
                <ProtectedRoute permission="users.manage">
                  <UsersPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/billing"
              element={
                <ProtectedRoute>
                  <BillingPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/products"
              element={
                <ProtectedRoute permission="products.view">
                  <ProductsPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/clubs"
              element={
                <ProtectedRoute requireSuperAdmin>
                  <ClubsPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/settings"
              element={
                <ProtectedRoute>
                  <SettingsPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/visits"
              element={
                <ProtectedRoute permission="visits.view">
                  <VisitsPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/arrivals"
              element={
                <ProtectedRoute permission="arrivals.view">
                  <ArrivalsPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/sklad"
              element={
                <ProtectedRoute permission="warehouse.view">
                  <WarehousePage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/cash"
              element={
                <ProtectedRoute permission="cash.view">
                  <CashPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/trainers"
              element={
                <ProtectedRoute permission="trainers.view">
                  <TrainersPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/equipment"
              element={
                <ProtectedRoute permission="equipment.view">
                  <EquipmentPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/group-sessions"
              element={
                <ProtectedRoute permission="group_sessions.view">
                  <GroupSessionsPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/access"
              element={
                <ProtectedRoute excludeTrainer>
                  <AccessPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/finance"
              element={
                <ProtectedRoute permission="finance.view">
                  <FinancePage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/certificates"
              element={
                <ProtectedRoute permission="certificates.view">
                  <CertificatesPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/prro"
              element={
                <ProtectedRoute permission="prro.manage">
                  <PrroSettingsPage />
                </ProtectedRoute>
              }
            />

            {/* /help і /support — одна об'єднана сторінка (HelpPage.jsx рендерить вкладки Довідка/Підтримка) */}
            <Route
              path="/help"
              element={
                <ProtectedRoute excludeTrainer>
                  <HelpPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/support"
              element={
                <ProtectedRoute excludeTrainer>
                  <HelpPage />
                </ProtectedRoute>
              }
            />

            <Route
              path="/client-service"
              element={
                <ProtectedRoute permission="clients.view">
                  <ClientServicePage />
                </ProtectedRoute>
              }
            />

            {/* Наступні сторінки додаються сюди у наступних фазах */}

            <Route path="*" element={<Navigate to="/dashboard" replace />} />
          </Routes>
        </BrowserRouter>
      </ToastProvider>
    </AuthProvider>
  );
}
