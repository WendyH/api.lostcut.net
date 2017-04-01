__version__ = '1.0'
__my_name__ = 'HDKinoteatr pages api scaner'

import logging
import os
import re
import sys
import traceback
from datetime import datetime, timedelta
from urllib.request import Request, urlopen

lastpage_match = re.compile('LastPage=(\d+)')
# global variables
stop_on_error = False 
url_api = "http://<your-site>.000webhostapp.com/hdkinoteatr/scanpage?token=<secret_token>&u=1&p="
total_pages = 2822 # last page number on the www.hdkinoteatr.com
count_pages = 0
pages_left  = 0

# global logger
logger = logging.getLogger('api')
logger_handler   = logging.FileHandler(os.path.splitext(__file__)[0]+'.log')
logger_formatter = logging.Formatter('%(asctime)s %(levelname)s %(message)s')
logger_handler.setFormatter(logger_formatter)
logger.addHandler(logger_handler) 
logger.setLevel(logging.INFO)

logger.info('---------- START -----------')
print("          ---== " + __my_name__ + ' v' + __version__ + " ==---\n")

time_begin = datetime.now()

def show_progress(page):
	pages_completed = total_pages - page
	if (pages_completed < 1): return
	seconds = (datetime.now() - time_begin).total_seconds();
	s = int(seconds);
	time_elapsed = '{:02}:{:02}:{:02}'.format(s // 3600, s % 3600 // 60, s % 60)
	time_remain  = ''
	if (count_pages > 0):
		sec_remain  = int(seconds * (total_pages - pages_left - count_pages) / count_pages);
		time_remain = ' Remain: {:02}:{:02}:{:02}'.format(sec_remain // 3600, sec_remain % 3600 // 60, sec_remain % 60)
	completed = (pages_completed * 100) / total_pages
	i = int(completed / 5)
	sys.stdout.write("[%-20s] %d%% Time: %s Page: %d Parsed %d/%d%s\r" % ('='*i, completed, time_elapsed, page, pages_completed, total_pages, time_remain))
	sys.stdout.flush()

def parse_pages():
	global count_pages, pages_left
	last_page  = total_pages
	file_state = os.path.splitext(__file__)[0]+'.state'
	if os.path.isfile(file_state):
		f = open(file_state)
		m = lastpage_match.match(f.read())
		if m:
			last_page = int(m.group(1))
			print('Starting page from saved state: '+str(last_page))
		else:
			print('Starting page: '+str(last_page))
	pages_left = total_pages - last_page
	# loading page loop --------
	for i in range(last_page, 0, -1):
		f = open(file_state, 'w')
		f.write('LastPage='+str(i)) # save last state
		show_progress(i)
		url = url_api+str(i)
		logger.info('Load page: '+ url)
		answ = ''
		try:
			req  = Request(url, headers={'User-Agent': 'Mozilla/5.0'})
			answ = str(urlopen(req).read())
		except:
			logger.error(str(sys.exc_info()))
			logger.info('Trying second time load the page...')
			try:
				req  = Request(url, headers={'User-Agent': 'Mozilla/5.0'})
				answ = str(urlopen(req).read())
			except:
				logger.error('Skipping page...')
				continue
		if (answ.find('successfully') < 0):
			logger.error(answ)
			if (stop_on_error): break
		else:
			logger.info('Answer: '+answ);
		count_pages += 1
		first_try  = True
	# --------------------------
	logger.info('Updated pages for this session: '+str(count_pages))

try:
	parse_pages()

except KeyboardInterrupt:
	print("\nBreaked...")

except:
	info = traceback.format_exc()
	logger.error(info)
	print('Unexpected error: '+info)

logger.info('---------- E N D -----------');

