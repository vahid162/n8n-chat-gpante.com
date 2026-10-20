"""Real PostgreSQL reservation race; run only while Main is inactive.

Uses the existing Docker/psql CLI, never calls a model. Temporary budget and
known-undispatched fixtures are restored in finally; actual charges are kept.
"""
import argparse
import concurrent.futures
import hashlib
import json
import subprocess
import uuid

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--container', default='project_db')
parser.add_argument('--database', default='project_db')
parser.add_argument('--user', required=True)
args = parser.parse_args()
command = ['docker', 'exec', '-i', args.container, 'psql', '-X', '-qAt', '-v', 'ON_ERROR_STOP=1', '-U', args.user, '-d', args.database]


def query(sql):
    result = subprocess.run(command, input=sql, text=True, capture_output=True, check=True, timeout=20)
    return json.loads(result.stdout.strip()) if result.stdout.strip() else None


original = query("SELECT row_to_json(r) FROM gpante_chat.runtime r;")
assert original and original['enabled'], 'Expected an enabled, configured draft'
assert query("SELECT count(*) FROM gpante_chat.requests WHERE NOT finished AND expires_at>clock_timestamp();") == 0, 'Other requests are active'
nonce = uuid.uuid4().hex
request_ids = []
client_ids = []
previous_id = 'fixture_previous_month_' + nonce
try:
    for i in range(2):
        sid = hashlib.sha256((nonce + 'session' + str(i)).encode()).hexdigest()
        cid = hashlib.sha256((nonce + 'client' + str(i)).encode()).hexdigest()
        client_ids.append(cid)
        admission = query("SELECT gpante_chat.admit('%s','%s','sendMessage');" % (sid, cid))
        assert admission['allowed'], admission
        request_ids.append(admission['requestId'])
    # A full previous-month charge must not consume this month's remaining budget.
    query("INSERT INTO gpante_chat.model_calls(request_id,call_index,month,cost_nano,state) VALUES ('%s',1,(date_trunc('month',clock_timestamp() AT TIME ZONE 'UTC')-interval '1 month')::date,2000000000,'burned');" % previous_id)
    used = query("SELECT coalesce(sum(cost_nano),0) FROM gpante_chat.model_calls WHERE month=date_trunc('month',clock_timestamp() AT TIME ZONE 'UTC')::date;")
    temporary_budget = used + 320000000
    assert temporary_budget <= original['monthly_budget_nano'], 'Insufficient current-month budget for this test'
    query('UPDATE gpante_chat.runtime SET monthly_budget_nano=%d;' % temporary_budget)
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(lambda rid: query("SELECT gpante_chat.reserve_call('%s',1);" % rid), request_ids))
    assert sum(result['allowed'] for result in results) == 1, results
    assert [result['reason'] for result in results if not result['allowed']] == ['monthly_budget'], results
    charged = query("SELECT coalesce(sum(cost_nano),0) FROM gpante_chat.model_calls WHERE month=date_trunc('month',clock_timestamp() AT TIME ZONE 'UTC')::date;")
    assert charged == temporary_budget, 'Concurrent reservations exceeded or missed the exact limit'
    assert query("SELECT to_json(bool_and(month=date_trunc('month',created_at AT TIME ZONE 'UTC')::date)) FROM gpante_chat.model_calls WHERE request_id IN ('%s','%s');" % tuple(request_ids)), 'Reservation month differs from UTC creation month'
    print(json.dumps({'simultaneousReservations': 2, 'accepted': 1, 'rejectedForBudget': 1, 'previousMonthExcluded': True, 'reservationMonthUTC': True, 'modelCalls': 0}))
finally:
    query('UPDATE gpante_chat.runtime SET monthly_budget_nano=%d;' % original['monthly_budget_nano'])
    # These reservations were test-only: no HTTP dispatch happened. Never use this
    # cleanup as a timeout refund for a real call with unknown provider billing.
    for rid in request_ids + [previous_id]:
        query("DELETE FROM gpante_chat.model_calls WHERE request_id='%s'; DELETE FROM gpante_chat.requests WHERE request_id='%s';" % (rid, rid))
    for cid in client_ids:
        query("DELETE FROM gpante_chat.request_counters WHERE counter_key IN ('client:%s','message:%s');" % (cid, cid))
