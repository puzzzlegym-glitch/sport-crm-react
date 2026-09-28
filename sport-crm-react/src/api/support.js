import { api } from './client';

export const listTickets = (status = '') => api('list_tickets', { status }, 'support');
export const getTicket = (ticketId) => api('get_ticket', { ticket_id: ticketId }, 'support');
export const createTicket = (subject, message) => api('create_ticket', { subject, message }, 'support');
export const sendMessage = (ticketId, message) => api('send_message', { ticket_id: ticketId, message }, 'support');
export const updateTicketStatus = (ticketId, status) => api('update_status', { ticket_id: ticketId, status }, 'support');
export const getUnreadSupportCount = () => api('unread_count', {}, 'support');
export const deleteTicket = (ticketId) => api('delete_ticket', { ticket_id: ticketId }, 'support');
