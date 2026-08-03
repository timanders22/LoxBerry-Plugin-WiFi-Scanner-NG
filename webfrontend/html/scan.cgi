#!/usr/bin/perl

# Copyright 2018 Dominik Holland, dominik.holland@googlemail.com
#
# Licensed under the Apache License, Version 2.0 (the "License");
# you may not use this file except in compliance with the License.
# You may obtain a copy of the License at
#
#     http://www.apache.org/licenses/LICENSE-2.0
#
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS,
# WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
# See the License for the specific language governing permissions and
# limitations under the License.


##########################################################################
# Modules
##########################################################################

use CGI;
use LoxBerry::System;
use LoxBerry::Web;

# Version of this script
my $version = LoxBerry::System::pluginversion();

# Read Form
my $cgi = CGI->new;
$cgi->import_names('R');

print "Content-Type: text/plain\n\n";

system("$lbpbindir/check.pl");

print "DONE !";

exit 0;
