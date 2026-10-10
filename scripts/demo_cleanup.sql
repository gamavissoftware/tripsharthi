-- Removes the DEMO data added to a workspace for demos/screenshots. Run through scripts/demo_cleanup.sh (dry-run by default).
--
-- Expects these session variables (the wrapper sets them):  @t = tenant id,  @apply = 0|1,  @reset_profile = 0|1
-- Everything is ONE transaction. @apply = 0 ends with ROLLBACK, so you see exactly what would be removed (and any
-- foreign-key problem) without changing a thing; @apply = 1 ends with COMMIT.
--
-- What counts as demo (nothing else is touched, so the owner's own records survive):
--   * contacts whose name contains "(Demo)", and EVERYTHING hanging off them: trips, deals, itineraries, bookings,
--     travellers, checklists, payments, supplier services + payments, issued documents (receipts / invoices / credit
--     notes of those bookings), seats, chats + messages, flow runs, tickets, tasks/meetings, attributions, lead events
--   * CRM deals titled "(Demo)"; tasks with description "Demo task"
--   * demo catalogue rows by their exact names: suppliers, rates, destinations, products, price books, group
--     departures, e-mail templates + campaigns, WhatsApp campaigns/templates (demo_*), web forms, portal lead
--     sources, the "travelling_from" custom field, the 7 starter automations (ONLY while still draft)
--   * demo ads (external_id 'demo-%') and the demo social page (page_id 'demo-%') with its posts
-- NOT touched: users, pipelines, dashboards, audit log, notifications, sales targets, FX rates, integrations, and the
-- business profile (unless @reset_profile = 1). Stored PDFs on disk (writable/uploads/invoices/<tenant>/) are not
-- removed - delete that folder's demo files by hand if you want them gone.

SET @t = IFNULL(@t, 0), @apply = IFNULL(@apply, 0), @reset_profile = IFNULL(@reset_profile, 0);

DROP TEMPORARY TABLE IF EXISTS r, d_contacts, d_trips, d_deals, d_bookings, d_itins, d_services, d_payments, d_convs, d_camps, d_flowruns, d_tickets, d_docs, d_sources, d_fields;
CREATE TEMPORARY TABLE r (step VARCHAR(60) NOT NULL, n INT NOT NULL);

CREATE TEMPORARY TABLE d_contacts (id INT PRIMARY KEY);
INSERT INTO d_contacts SELECT id FROM contacts WHERE tenant_id = @t AND name LIKE '%(Demo)%';

CREATE TEMPORARY TABLE d_trips (id INT PRIMARY KEY, deal_id INT NULL);
INSERT INTO d_trips SELECT id, deal_id FROM trips WHERE tenant_id = @t AND (contact_id IN (SELECT id FROM d_contacts) OR title LIKE '%(Demo)%');

CREATE TEMPORARY TABLE d_deals (id INT PRIMARY KEY);
INSERT IGNORE INTO d_deals SELECT deal_id FROM d_trips WHERE deal_id IS NOT NULL;
INSERT IGNORE INTO d_deals SELECT id FROM deals WHERE tenant_id = @t AND title LIKE '%(Demo)%';

CREATE TEMPORARY TABLE d_bookings (id INT PRIMARY KEY);
INSERT INTO d_bookings SELECT id FROM bookings WHERE tenant_id = @t AND (trip_id IN (SELECT id FROM d_trips) OR contact_id IN (SELECT id FROM d_contacts));

CREATE TEMPORARY TABLE d_itins (id INT PRIMARY KEY);
INSERT INTO d_itins SELECT id FROM itineraries WHERE tenant_id = @t AND trip_id IN (SELECT id FROM d_trips);

CREATE TEMPORARY TABLE d_services (id INT PRIMARY KEY);
INSERT INTO d_services SELECT id FROM booking_services WHERE tenant_id = @t AND booking_id IN (SELECT id FROM d_bookings);

CREATE TEMPORARY TABLE d_payments (id INT PRIMARY KEY);
INSERT INTO d_payments SELECT id FROM booking_payments WHERE tenant_id = @t AND booking_id IN (SELECT id FROM d_bookings);

CREATE TEMPORARY TABLE d_convs (id INT PRIMARY KEY);
INSERT INTO d_convs SELECT id FROM conversations WHERE tenant_id = @t AND contact_id IN (SELECT id FROM d_contacts);

CREATE TEMPORARY TABLE d_camps (id INT PRIMARY KEY);
INSERT INTO d_camps SELECT id FROM campaigns WHERE tenant_id = @t AND name LIKE '%(Demo)%';

CREATE TEMPORARY TABLE d_flowruns (id INT PRIMARY KEY);
INSERT INTO d_flowruns SELECT id FROM flow_runs WHERE tenant_id = @t AND contact_id IN (SELECT id FROM d_contacts);

CREATE TEMPORARY TABLE d_tickets (id INT PRIMARY KEY);
INSERT INTO d_tickets SELECT id FROM tickets WHERE tenant_id = @t AND contact_id IN (SELECT id FROM d_contacts);

CREATE TEMPORARY TABLE d_docs (id INT PRIMARY KEY);
INSERT INTO d_docs SELECT id FROM invoices WHERE tenant_id = @t AND booking_id IN (SELECT id FROM d_bookings);

CREATE TEMPORARY TABLE d_sources (id INT PRIMARY KEY);
INSERT INTO d_sources SELECT id FROM lead_sources WHERE tenant_id = @t AND name LIKE 'Demo Travel Portal%';

CREATE TEMPORARY TABLE d_fields (id INT PRIMARY KEY);
INSERT INTO d_fields SELECT id FROM custom_fields WHERE tenant_id = @t AND field_key = 'travelling_from';

START TRANSACTION;

-- ---- messaging ---------------------------------------------------------------------------------------------------
DELETE FROM flow_run_logs WHERE flow_run_id IN (SELECT id FROM d_flowruns);                                           INSERT INTO r VALUES ('flow run logs', ROW_COUNT());
DELETE FROM flow_runs WHERE tenant_id = @t AND id IN (SELECT id FROM d_flowruns);                                      INSERT INTO r VALUES ('flow runs', ROW_COUNT());
DELETE FROM click_events WHERE tenant_id = @t AND (contact_id IN (SELECT id FROM d_contacts) OR campaign_id IN (SELECT id FROM d_camps));  INSERT INTO r VALUES ('click events', ROW_COUNT());
DELETE FROM messages WHERE tenant_id = @t AND (contact_id IN (SELECT id FROM d_contacts) OR conversation_id IN (SELECT id FROM d_convs) OR campaign_id IN (SELECT id FROM d_camps));  INSERT INTO r VALUES ('messages', ROW_COUNT());
DELETE FROM conversations WHERE tenant_id = @t AND id IN (SELECT id FROM d_convs);                                     INSERT INTO r VALUES ('conversations', ROW_COUNT());
DELETE FROM campaigns WHERE tenant_id = @t AND id IN (SELECT id FROM d_camps);                                         INSERT INTO r VALUES ('whatsapp campaigns', ROW_COUNT());
DELETE FROM templates WHERE tenant_id = @t AND (name LIKE 'demo\_%' OR (name IN ('tp_booking_confirmed','tp_passport_expiring','tp_trip_feedback','tp_departure_checklist','tp_quote_unopened_followup','tp_quote_viewed_followup','tp_trip_started') AND meta_status = 'draft'));  INSERT INTO r VALUES ('whatsapp templates', ROW_COUNT());
DELETE FROM flows WHERE tenant_id = @t AND status = 'draft' AND name IN ('Quote follow-up — viewed, not accepted','Quote follow-up — not opened','Booking confirmed — welcome','Pre-departure checklist (7 days)','Passport expiry alert (international)','Wishing a great trip (departure day)','Post-trip feedback (1 day after)');  INSERT INTO r VALUES ('starter flows (draft)', ROW_COUNT());

-- ---- e-mail marketing + lead capture -----------------------------------------------------------------------------------
DELETE FROM email_campaigns WHERE tenant_id = @t AND name IN ('Diwali getaways — November blast','Bali spotlight — January','Win-back — cold enquiries','Dubai December — early-bird');  INSERT INTO r VALUES ('email campaigns', ROW_COUNT());
DELETE FROM email_templates WHERE tenant_id = @t AND name IN ('Festive offers — newsletter','Destination spotlight','Win-back — past enquiry','Booking confirmation','Payment reminder','Post-trip feedback','Monsoon & off-season deals');  INSERT INTO r VALUES ('email templates', ROW_COUNT());
DELETE FROM web_forms WHERE tenant_id = @t AND title LIKE '%(Demo)%';                                                  INSERT INTO r VALUES ('web forms', ROW_COUNT());
DELETE FROM lead_events WHERE tenant_id = @t AND (lead_source_id IN (SELECT id FROM d_sources) OR contact_id IN (SELECT id FROM d_contacts));  INSERT INTO r VALUES ('lead events', ROW_COUNT());
DELETE FROM lead_sources WHERE tenant_id = @t AND id IN (SELECT id FROM d_sources);                                    INSERT INTO r VALUES ('portal lead sources', ROW_COUNT());
DELETE FROM contact_field_values WHERE custom_field_id IN (SELECT id FROM d_fields);                                   INSERT INTO r VALUES ('custom field values', ROW_COUNT());
DELETE FROM custom_fields WHERE tenant_id = @t AND id IN (SELECT id FROM d_fields);                                    INSERT INTO r VALUES ('custom fields', ROW_COUNT());

-- ---- ads + social ---------------------------------------------------------------------------------------------------
DELETE FROM ad_insights_daily WHERE tenant_id = @t AND campaign_external_id LIKE 'demo-%';                            INSERT INTO r VALUES ('ad daily insights', ROW_COUNT());
DELETE FROM ad_issues WHERE tenant_id = @t AND campaign_external_id LIKE 'demo-%';                                     INSERT INTO r VALUES ('ad issues', ROW_COUNT());
DELETE FROM ad_campaigns WHERE tenant_id = @t AND external_id LIKE 'demo-%';                                           INSERT INTO r VALUES ('ad campaigns', ROW_COUNT());
DELETE FROM lead_attributions WHERE tenant_id = @t AND (campaign_id LIKE 'demo-%' OR contact_id IN (SELECT id FROM d_contacts) OR trip_id IN (SELECT id FROM d_trips));  INSERT INTO r VALUES ('lead attributions', ROW_COUNT());
DELETE FROM conversion_events WHERE tenant_id = @t AND (contact_id IN (SELECT id FROM d_contacts) OR trip_id IN (SELECT id FROM d_trips));  INSERT INTO r VALUES ('conversion events', ROW_COUNT());
DELETE FROM social_posts WHERE tenant_id = @t AND social_account_id IN (SELECT id FROM social_accounts WHERE tenant_id = @t AND page_id LIKE 'demo-%');  INSERT INTO r VALUES ('social posts', ROW_COUNT());
DELETE FROM social_accounts WHERE tenant_id = @t AND page_id LIKE 'demo-%';                                            INSERT INTO r VALUES ('social pages', ROW_COUNT());

-- ---- travel: bookings and everything under them -------------------------------------------------------------------------
DELETE FROM supplier_payments WHERE tenant_id = @t AND (booking_id IN (SELECT id FROM d_bookings) OR booking_service_id IN (SELECT id FROM d_services));  INSERT INTO r VALUES ('supplier payments', ROW_COUNT());
DELETE FROM payment_reminders WHERE tenant_id = @t AND (booking_id IN (SELECT id FROM d_bookings) OR booking_payment_id IN (SELECT id FROM d_payments));  INSERT INTO r VALUES ('payment reminders', ROW_COUNT());
DELETE FROM document_deliveries WHERE tenant_id = @t AND booking_id IN (SELECT id FROM d_bookings);                    INSERT INTO r VALUES ('document deliveries', ROW_COUNT());
DELETE FROM invoices WHERE tenant_id = @t AND id IN (SELECT id FROM d_docs);                                            INSERT INTO r VALUES ('issued documents (receipt/invoice/credit note)', ROW_COUNT());
DELETE FROM booking_checklist_items WHERE tenant_id = @t AND booking_id IN (SELECT id FROM d_bookings);                INSERT INTO r VALUES ('checklist items', ROW_COUNT());
DELETE FROM portal_uploads WHERE tenant_id = @t AND booking_id IN (SELECT id FROM d_bookings);                         INSERT INTO r VALUES ('portal uploads', ROW_COUNT());
DELETE FROM travelers WHERE tenant_id = @t AND booking_id IN (SELECT id FROM d_bookings);                              INSERT INTO r VALUES ('travellers', ROW_COUNT());
DELETE FROM booking_payments WHERE tenant_id = @t AND id IN (SELECT id FROM d_payments);                                INSERT INTO r VALUES ('customer payments', ROW_COUNT());
DELETE FROM booking_services WHERE tenant_id = @t AND id IN (SELECT id FROM d_services);                                INSERT INTO r VALUES ('supplier services', ROW_COUNT());
DELETE FROM departure_seats WHERE tenant_id = @t AND (trip_id IN (SELECT id FROM d_trips) OR booking_id IN (SELECT id FROM d_bookings) OR contact_id IN (SELECT id FROM d_contacts));  INSERT INTO r VALUES ('seat holds', ROW_COUNT());
DELETE FROM bookings WHERE tenant_id = @t AND id IN (SELECT id FROM d_bookings);                                        INSERT INTO r VALUES ('bookings', ROW_COUNT());
DELETE FROM itinerary_items WHERE tenant_id = @t AND itinerary_id IN (SELECT id FROM d_itins);                          INSERT INTO r VALUES ('itinerary items', ROW_COUNT());
DELETE FROM itinerary_days WHERE tenant_id = @t AND itinerary_id IN (SELECT id FROM d_itins);                           INSERT INTO r VALUES ('itinerary days', ROW_COUNT());
DELETE FROM itineraries WHERE tenant_id = @t AND id IN (SELECT id FROM d_itins);                                        INSERT INTO r VALUES ('itineraries', ROW_COUNT());
DELETE FROM travel_trigger_log WHERE tenant_id = @t AND ((entity_type = 'trip' AND entity_id IN (SELECT id FROM d_trips)) OR (entity_type = 'booking' AND entity_id IN (SELECT id FROM d_bookings)));  INSERT INTO r VALUES ('trigger log', ROW_COUNT());
DELETE FROM trips WHERE tenant_id = @t AND id IN (SELECT id FROM d_trips);                                              INSERT INTO r VALUES ('trips', ROW_COUNT());

-- ---- CRM records (deals, tickets, tasks, meetings, notes) ------------------------------------------------------------------
DELETE FROM notes WHERE tenant_id = @t AND ((related_type = 'ticket' AND related_id IN (SELECT id FROM d_tickets)) OR (related_type = 'contact' AND related_id IN (SELECT id FROM d_contacts)) OR (related_type = 'deal' AND related_id IN (SELECT id FROM d_deals)));  INSERT INTO r VALUES ('notes', ROW_COUNT());
DELETE FROM activities WHERE tenant_id = @t AND ((related_type = 'ticket' AND related_id IN (SELECT id FROM d_tickets)) OR (related_type = 'contact' AND related_id IN (SELECT id FROM d_contacts)) OR (related_type = 'deal' AND related_id IN (SELECT id FROM d_deals)));  INSERT INTO r VALUES ('activities', ROW_COUNT());
DELETE FROM tickets WHERE tenant_id = @t AND id IN (SELECT id FROM d_tickets);                                           INSERT INTO r VALUES ('tickets', ROW_COUNT());
DELETE FROM tasks WHERE tenant_id = @t AND (description = 'Demo task' OR (related_type = 'contact' AND related_id IN (SELECT id FROM d_contacts)) OR (related_type = 'deal' AND related_id IN (SELECT id FROM d_deals)));  INSERT INTO r VALUES ('tasks', ROW_COUNT());
DELETE FROM meetings WHERE tenant_id = @t AND (contact_id IN (SELECT id FROM d_contacts) OR deal_id IN (SELECT id FROM d_deals));  INSERT INTO r VALUES ('meetings', ROW_COUNT());
DELETE FROM follow_ups WHERE tenant_id = @t AND contact_id IN (SELECT id FROM d_contacts);                              INSERT INTO r VALUES ('follow-ups', ROW_COUNT());
DELETE FROM emails WHERE tenant_id = @t AND (contact_id IN (SELECT id FROM d_contacts) OR deal_id IN (SELECT id FROM d_deals));  INSERT INTO r VALUES ('emails', ROW_COUNT());
DELETE FROM deal_contacts WHERE tenant_id = @t AND (deal_id IN (SELECT id FROM d_deals) OR contact_id IN (SELECT id FROM d_contacts));  INSERT INTO r VALUES ('deal-contact links', ROW_COUNT());
DELETE FROM deal_line_items WHERE tenant_id = @t AND deal_id IN (SELECT id FROM d_deals);                               INSERT INTO r VALUES ('deal line items', ROW_COUNT());
DELETE FROM quotes WHERE tenant_id = @t AND deal_id IN (SELECT id FROM d_deals);                                         INSERT INTO r VALUES ('quotes', ROW_COUNT());
DELETE FROM deals WHERE tenant_id = @t AND id IN (SELECT id FROM d_deals);                                               INSERT INTO r VALUES ('deals', ROW_COUNT());

-- ---- the demo customers themselves -----------------------------------------------------------------------------------------
DELETE FROM contact_tags WHERE contact_id IN (SELECT id FROM d_contacts);                                                INSERT INTO r VALUES ('contact tags', ROW_COUNT());
DELETE FROM contact_field_values WHERE contact_id IN (SELECT id FROM d_contacts);                                        INSERT INTO r VALUES ('contact field values', ROW_COUNT());
DELETE FROM contacts WHERE tenant_id = @t AND id IN (SELECT id FROM d_contacts);                                         INSERT INTO r VALUES ('contacts', ROW_COUNT());

-- ---- demo catalogue (exact names, so real suppliers/products you add later are never swept up) ----------------------------------
DELETE FROM supplier_rates WHERE tenant_id = @t AND supplier_id IN (SELECT id FROM suppliers WHERE tenant_id = @t AND name IN ('Ubud Jungle Villas','Azure Atoll Resort','Skyline Dubai Hotels','Desert Gold Tours','Backwater Stays Kerala','Palm Grove Resorts','Himalayan Houseboats','Island Ride Transfers','Sunrise Sightseeing'));  INSERT INTO r VALUES ('rate cards', ROW_COUNT());
DELETE FROM suppliers WHERE tenant_id = @t AND name IN ('Ubud Jungle Villas','Azure Atoll Resort','Skyline Dubai Hotels','Desert Gold Tours','Backwater Stays Kerala','Palm Grove Resorts','Himalayan Houseboats','Island Ride Transfers','Sunrise Sightseeing');  INSERT INTO r VALUES ('suppliers', ROW_COUNT());
DELETE FROM departures WHERE tenant_id = @t AND code IN ('BALI-JAN','KASH-MAY','DUBAI-DEC','KERALA-NOV');                INSERT INTO r VALUES ('group departures', ROW_COUNT());
DELETE FROM destinations WHERE tenant_id = @t AND name IN ('Bali','Maldives','Dubai','Thailand','Singapore','Kerala','Goa','Kashmir','Rajasthan','Andaman');  INSERT INTO r VALUES ('destinations', ROW_COUNT());
DELETE FROM price_book_entries WHERE tenant_id = @t AND price_book_id IN (SELECT id FROM price_books WHERE tenant_id = @t AND name IN ('Corporate & group rates (−10%)','Peak season Dec–Jan (+15%)'));  INSERT INTO r VALUES ('price book entries', ROW_COUNT());
DELETE FROM price_books WHERE tenant_id = @t AND name IN ('Corporate & group rates (−10%)','Peak season Dec–Jan (+15%)');  INSERT INTO r VALUES ('price books', ROW_COUNT());
DELETE FROM products WHERE tenant_id = @t AND retailer_id LIKE 'BH-%';                                                   INSERT INTO r VALUES ('products', ROW_COUNT());

-- ---- optional: back to a blank business profile (only when no real documents remain) -----------------------------------------------
DELETE FROM business_profiles WHERE tenant_id = @t AND @reset_profile = 1 AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.tenant_id = @t);  INSERT INTO r VALUES ('business profile (reset)', ROW_COUNT());
DELETE FROM doc_sequences WHERE tenant_id = @t AND @reset_profile = 1 AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.tenant_id = @t);   INSERT INTO r VALUES ('document counters (reset)', ROW_COUNT());

SELECT step AS `removed / would remove`, n AS rows_ FROM r WHERE n > 0 ORDER BY step;
SELECT IF(@apply = 1, 'APPLIED - committed', 'DRY RUN - rolled back, nothing changed (re-run with --apply)') AS result;

-- A real COMMIT only when asked; otherwise undo everything.
SET @finish = IF(@apply = 1, 'COMMIT', 'ROLLBACK');
PREPARE fin FROM @finish; EXECUTE fin; DEALLOCATE PREPARE fin;
