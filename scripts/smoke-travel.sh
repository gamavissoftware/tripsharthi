# Local by default. Against a live server use YOUR OWN TEST ACCOUNT (this creates a test trip, quote, booking and payment in it):
#   BASE=https://app.tripsarthi.com/api/v1 SMOKE_EMAIL=you@x.com SMOKE_PASSWORD='…' bash scripts/smoke-travel.sh
B="${BASE:-http://localhost:8731/api/v1}"; J='Content-Type: application/json'
SMOKE_EMAIL="${SMOKE_EMAIL:-demo@travelpilot.test}"; SMOKE_PASSWORD="${SMOKE_PASSWORD:-Demo@12345}"
TOK=$(curl -s -XPOST $B/auth/login -H "$J" -d "{\"email\":\"$SMOKE_EMAIL\",\"password\":\"$SMOKE_PASSWORD\"}" | python3 -c "import sys,json;print(json.load(sys.stdin).get('token',''))")
echo TOK=${TOK:0:6}
A="Authorization: Bearer $TOK"
c(){ curl -s -X"$1" "$B$2" -H "$J" -H "$A" ${3:+-d "$3"}; }
j(){ python3 -c "import sys,json;d=json.load(sys.stdin);$1"; }
c POST /contacts '{"name":"Rohit Sharma","wa_number":"919876543210","source":"manual"}' | j "print('contact',d.get('data',d).get('id') if isinstance(d.get('data',d),dict) else d)"
c POST /trips '{"title":"Bali honeymoon","contact_id":1,"trip_type":"honeymoon","destination_text":"Bali","is_international":1,"start_date":"2027-01-20","end_date":"2027-01-26","adults":2,"budget_max":8000000,"interests":["beach","spa"]}' | j "print('trip',d.get('data',d))"
c POST /itineraries '{"trip_id":1,"markup_value":15}' | j "print('it',d['data']['id'],d['data']['grand_total'])"
c POST /itineraries/1/days '{"title":"Arrival in Bali"}' | j "print('day',d['data']['id'])"
c POST /itineraries/1/items '{"day_id":1,"type":"hotel","title":"Ubud Villa","unit_cost":800000,"nights":5}' | j "print('item',d['data']['cost_amount'])"
c POST /itineraries/1/items '{"type":"transfer","title":"Airport transfers","unit_cost":200000}' >/dev/null
c GET /itineraries/1 | j "d=d['data'];print({k:d[k] for k in ['cost_total','sell_subtotal','gst_amount','tcs_amount','grand_total','margin_amount']})"
TK=$(c POST /itineraries/1/share | j "print(d['data']['share_token'])")
curl -s $B/public/quotes/$TK | j "d=d['data'];print('public leaks cost:', 'cost_total' in d or 'margin_amount' in d or any('cost_amount' in i for x in d['days'] for i in x['items']), d['status'])"
curl -s -XPOST $B/public/quotes/$TK/accept | head -c 80; echo
c POST /bookings '{"itinerary_id":1}' | j "d=d.get('data',d);print(d.get('booking_ref'),d.get('total_amount'),[(p['label'],p['amount'],p['due_date']) for p in d.get('payments',[])],len(d.get('services',[])))"
c POST /bookings/payments/1/paid '{"mode":"upi"}' | j "d=d['data'];print('paid',d['paid_amount'],'due',d['due_amount'])"
c GET /bookings/dashboard | head -c 250; echo
c GET /trips/1 | j "print('trip status',d['data']['status'])"
